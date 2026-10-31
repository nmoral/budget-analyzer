<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use App\Domain\Cost\CohortHorizonCalculator;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\ViewAwareStepCost;
use App\Domain\Shared\Amount;
use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\RetirementScheme;
use App\Infrastructure\Entity\Source;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Horizon cohorte dans l'API résultat (#66, J3) : C2 (série année par année par
 * ligne/vue + agrégat aligné par index d'année), C2.3 (view-aware : terme retraite
 * suivant la vue) et C4 (vue non valorisable → 422).
 */
final class ScenarioResultCohortApiTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private const string RECRUITMENT = '2026-09-01';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->createScenarioSchema($this->em);
        $this->resultPool()->clear();
    }

    protected function tearDown(): void
    {
        $this->resultPool()->clear();
        $this->dropScenarioSchema($this->em);
        $this->em->clear();
        $this->em->close();

        parent::tearDown();
    }

    /**
     * C2.1 : série cohorte par agent sous `results[0].results.BUDGETAIRE.cohorte`
     * = { perYear:[m₁..m_N], total } ; N = longueur de carrière ; année k = mois
     * [(k−1)·12, k·12) depuis l'entrée ; total = Σ perYear terme par terme.
     */
    public function testCohortNominalPerYearAndTotal(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=cohorte&views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $cohorte = $this->cohortBlock($body, 0, 'BUDGETAIRE');

        $expected = $this->cohortCalculator()->computeFullCareer($this->career(60), new \DateTimeImmutable(self::RECRUITMENT), 1);
        $expectedPerYear = $expected->perYear();

        // N = longueur de carrière : 24 mois non terminaux → 2 ans.
        self::assertCount(2, $cohorte['perYear']);
        foreach ($expectedPerYear as $year => $cost) {
            $this->assertCostMatches($cost, $cohorte['perYear'][$year]);
        }

        // total = Σ perYear terme par terme.
        $this->assertCostMatches($expected->totalPerAgent(), $cohorte['total']);
    }

    /**
     * C2.3 : la cohorte est view-aware — pour deux vues (BUDGETAIRE, EQUIVALENT_PRIVE),
     * `results[view].cohorte.perYear` diffère année par année sur le SEUL terme retraite
     * (3 autres termes égaux), même longueur. BUDGETAIRE reste égal au coût de base.
     */
    public function testCohortIsViewAwareOnRetirementOnly(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=cohorte&views=BUDGETAIRE,EQUIVALENT_PRIVE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $budget = $this->cohortBlock($body, 0, 'BUDGETAIRE');
        $private = $this->cohortBlock($body, 0, 'EQUIVALENT_PRIVE');

        self::assertSameSize($budget['perYear'], $private['perYear']);

        $date = new \DateTimeImmutable(self::RECRUITMENT);
        $base = $this->cohortCalculator()->computeFullCareer($this->career(60), $date, 1)->perYear();
        $privateExpected = $this->cohortCalculator()
            ->computeFullCareer($this->career(60), $date, 1, new ViewAwareStepCost($this->viewCalculator(), 'EQUIVALENT_PRIVE'))
            ->perYear();

        foreach ($budget['perYear'] as $year => $budgetYear) {
            $privateYear = $private['perYear'][$year];

            // Seul le terme retraite (et le total) diffère entre vues chaque année.
            self::assertNotSame($budgetYear['retirement'], $privateYear['retirement']);
            self::assertNotSame($budgetYear['total'], $privateYear['total']);
            self::assertSame($budgetYear['treatmentAndPrimes'], $privateYear['treatmentAndPrimes']);
            self::assertSame($budgetYear['otherContributions'], $privateYear['otherContributions']);
            self::assertSame($budgetYear['rafp'], $privateYear['rafp']);

            // BUDGETAIRE == base ; EQUIVALENT_PRIVE == série view-aware.
            self::assertSame($base[$year]->retirement()->toString(), $budgetYear['retirement']);
            self::assertSame($privateExpected[$year]->retirement()->toString(), $privateYear['retirement']);
        }
    }

    /**
     * C2.2 : agrégat cohorte aligné par index d'année — 2 lignes de carrières 5 et 3 ans
     * (volumes 2 et 3). `aggregate.BUDGETAIRE.cohorte.perYear` a 5 entrées ; chaque année
     * k = Σ(perYear[k] × volume) ; années 4-5 : seule la ligne longue contribue (courte = 0).
     */
    public function testCohortAggregateAlignedByYearIndex(): void
    {
        [$long, $short] = $this->seedSharedGrilleProfiles();
        $id = $this->createScenario($long, [
            ['profileRef' => $long->id, 'volume' => 2, 'recruitmentDate' => self::RECRUITMENT],
            ['profileRef' => $short->id, 'volume' => 3, 'recruitmentDate' => self::RECRUITMENT],
        ]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=cohorte&views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        /** @var array{aggregate: array<string, array{cohorte: array{perYear: list<array<string, string>>, total: array<string, string>}}>} $body */
        $aggregate = $body['aggregate']['BUDGETAIRE']['cohorte'];

        $date = new \DateTimeImmutable(self::RECRUITMENT);
        $cohort = $this->cohortCalculator();
        $longSeries = $cohort->computeFullCareer($this->longCareer(), $date, 1)->perYear();
        $shortSeries = $cohort->computeFullCareer($this->shortCareer(), $date, 1)->perYear();

        self::assertCount(5, $longSeries);
        self::assertCount(3, $shortSeries);
        self::assertCount(5, $aggregate['perYear']);

        $totalRetirement = Amount::fromString('0');
        foreach ($aggregate['perYear'] as $year => $aggYear) {
            $expected = $longSeries[$year]->retirement()->multipliedByIndex(2);
            if (isset($shortSeries[$year])) {
                $expected = $expected->plus($shortSeries[$year]->retirement()->multipliedByIndex(3));
            }
            self::assertSame($expected->toString(), $aggYear['retirement']);
            $totalRetirement = $totalRetirement->plus($expected);
        }

        // Années 4-5 (index 3-4) : seule la ligne longue (×2) contribue.
        self::assertSame($longSeries[3]->retirement()->multipliedByIndex(2)->toString(), $aggregate['perYear'][3]['retirement']);
        self::assertSame($longSeries[4]->retirement()->multipliedByIndex(2)->toString(), $aggregate['perYear'][4]['retirement']);

        // total = Σ perYear terme par terme.
        self::assertSame($totalRetirement->toString(), $aggregate['total']['retirement']);
    }

    /**
     * C4.2 : `horizons=cohorte` sur un profil dont un paramètre daté nécessaire à la
     * valorisation manque (point_indice) → 422 (UncomputableView), pas de 500.
     */
    public function testCohortUnderParameterizedReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-no-point');
        $profile = $this->persistComputableProfile($this->em, $set, omitKeys: ['point_indice']);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=cohorte&views=BUDGETAIRE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('BUDGETAIRE', $body['error']);
        self::assertStringContainsString('point_indice', $body['error']);
    }

    /**
     * Carrière PE/FPE/CAS_CIVIL à 3 échelons : IM 395 (12 mois), IM 420 (12 mois), IM
     * 450 (terminal, `$terminalMonths` mois). Miroir de la grille seedée.
     */
    private function career(int $terminalMonths): Career
    {
        return new Career(
            new CareerStep(new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 420, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 450, 'CAS_CIVIL'), $terminalMonths),
        );
    }

    /**
     * Carrière longue (entrée rang 1) : IM 395/420/450/480/510 (12 mois) + terminal IM 540.
     */
    private function longCareer(): Career
    {
        return new Career(
            new CareerStep(new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 420, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 450, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 480, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 510, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 540, 'CAS_CIVIL'), 60),
        );
    }

    /**
     * Carrière courte (entrée rang 3) : IM 450/480/510 (12 mois) + terminal IM 540.
     */
    private function shortCareer(): Career
    {
        return new Career(
            new CareerStep(new AgentSituation('PE', 'FPE', 450, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 480, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 510, 'CAS_CIVIL'), 12),
            new CareerStep(new AgentSituation('PE', 'FPE', 540, 'CAS_CIVIL'), 60),
        );
    }

    /**
     * @param list<array{rank: int, increasedIndex: int, durationMonths: int|null}> $extraEchelons
     */
    private function seedMultiEchelonProfile(array $extraEchelons): Profile
    {
        $set = $this->persistParameterSet($this->em, 'ver-cohorte');
        $profile = $this->persistComputableProfile($this->em, $set);

        $grille = $profile->entryEchelon->grille;
        foreach ($extraEchelons as $spec) {
            $echelon = new Echelon();
            $echelon->grille = $grille;
            $echelon->rank = $spec['rank'];
            $echelon->grossIndex = $spec['increasedIndex'] + 10;
            $echelon->increasedIndex = $spec['increasedIndex'];
            $echelon->durationMonths = $spec['durationMonths'];
            $this->em->persist($echelon);
        }
        $this->em->flush();

        return $profile;
    }

    /**
     * Deux profils partageant une MÊME grille (6 échelons, ranks 1-6, terminal rang 6),
     * le même corps/régime/financeur et les mêmes paramètres datés, mais des échelons
     * d'entrée différents : le long entre au rang 1 (carrière 5 ans), le court au rang 3
     * (carrière 3 ans). La carrière est dérivée par la factory à partir de l'entrée.
     *
     * @return array{0: Profile, 1: Profile}
     */
    private function seedSharedGrilleProfiles(): array
    {
        $set = $this->persistParameterSet($this->em, 'ver-cohorte');

        $corps = new Corps();
        $corps->code = 'PE';
        $corps->label = 'Professeur des écoles';
        $corps->versant = 'FPE';
        $corps->category = 'A';
        $this->em->persist($corps);

        $source = new Source();
        $source->code = 'SRC-grille-cohorte';
        $source->label = 'Source grille';
        $source->publisher = 'Éditeur';
        $source->edition = '2026';
        $source->locator = 'p. 1';
        $source->url = 'https://example.test/grille';
        $source->retrievedAt = new \DateTimeImmutable('2024-01-01');
        $source->fileHash = 'hash-grille-cohorte';
        $this->em->persist($source);

        $grille = new Grille();
        $grille->body = $corps;
        $grille->grade = 'classe normale';
        $grille->version = '2024';
        $grille->startDate = new \DateTimeImmutable('2020-01-01');
        $grille->endDate = null;
        $grille->source = $source;
        $grille->parameterSet = $set;
        $this->em->persist($grille);

        $indices = [1 => 395, 2 => 420, 3 => 450, 4 => 480, 5 => 510, 6 => 540];
        $echelons = [];
        foreach ($indices as $rank => $increasedIndex) {
            $echelon = new Echelon();
            $echelon->grille = $grille;
            $echelon->rank = $rank;
            $echelon->grossIndex = $increasedIndex + 10;
            $echelon->increasedIndex = $increasedIndex;
            $echelon->durationMonths = $rank === 6 ? null : 12;
            $this->em->persist($echelon);
            $echelons[$rank] = $echelon;
        }

        $scheme = new RetirementScheme();
        $scheme->code = 'CAS_CIVIL';
        $scheme->label = 'CAS civil';
        $this->em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT';
        $funder->label = 'État';
        $this->em->persist($funder);

        $this->persistEngineParameters($this->em, $set, $corps);

        $long = $this->makeProfile($corps, $echelons[1], $scheme, $funder);
        $short = $this->makeProfile($corps, $echelons[3], $scheme, $funder);
        $this->em->flush();

        return [$long, $short];
    }

    private function makeProfile(Corps $corps, Echelon $entry, RetirementScheme $scheme, Funder $funder): Profile
    {
        $profile = new Profile();
        $profile->body = $corps;
        $profile->grade = 'classe normale';
        $profile->entryEchelon = $entry;
        $profile->retirementScheme = $scheme;
        $profile->indemnitaryScheme = null;
        $profile->funder = $funder;
        $profile->status = 'titulaire';
        $profile->referenceCalendar = 'scolaire';
        $this->em->persist($profile);

        return $profile;
    }

    private function cohortCalculator(): CohortHorizonCalculator
    {
        $calculator = static::getContainer()->get(CohortHorizonCalculator::class);
        self::assertInstanceOf(CohortHorizonCalculator::class, $calculator);

        return $calculator;
    }

    private function viewCalculator(): CostViewCalculator
    {
        $calculator = static::getContainer()->get(CostViewCalculator::class);
        self::assertInstanceOf(CostViewCalculator::class, $calculator);

        return $calculator;
    }

    /**
     * @param array<string, string> $actual
     */
    private function assertCostMatches(AnnualCost $expected, array $actual): void
    {
        self::assertSame($expected->treatmentAndPrimes()->toString(), $actual['treatmentAndPrimes']);
        self::assertSame($expected->retirement()->toString(), $actual['retirement']);
        self::assertSame($expected->otherContributions()->toString(), $actual['otherContributions']);
        self::assertSame($expected->rafp()->toString(), $actual['rafp']);
        self::assertSame($expected->total()->toString(), $actual['total']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{perYear: list<array<string, string>>, total: array<string, string>}
     */
    private function cohortBlock(array $body, int $lineIndex, string $view): array
    {
        /** @var array{lines: list<array{results: array<string, array{cohorte: array{perYear: list<array<string, string>>, total: array<string, string>}}>}>} $body */
        return $body['lines'][$lineIndex]['results'][$view]['cohorte'];
    }

    private function resultPool(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get('app.cache.scenario_result');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function createScenario(Profile $profile, array $lines): int
    {
        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['title' => 'Cohorte', 'lines' => $lines]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['id'];
        self::assertIsInt($id);

        return $id;
    }
}
