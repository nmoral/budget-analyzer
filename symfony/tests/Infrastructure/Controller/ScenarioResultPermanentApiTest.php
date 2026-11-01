<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\PermanentRegimeCalculator;
use App\Domain\Cost\PermanentRegimeCost;
use App\Domain\Cost\ViewAwareStepCost;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Profile;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Horizon régime permanent dans l'API résultat (#66, J2) : C1 (coût par agent par
 * ligne/vue + agrégat ×volume ; durée terminale) et C4 (vue non valorisable → 422).
 */
final class ScenarioResultPermanentApiTest extends WebTestCase
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
     * C1.1 : coût permanent par agent sous `results[0].results.BUDGETAIRE.permanent`
     * (4 termes + total) ; total = somme exacte des 4 termes.
     */
    public function testPermanentPerAgentNominal(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent&views=BUDGETAIRE');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        $expected = $this->expectedPermanent()->averagePerAgent();
        $permanent = $this->permanentBlock($body, 0, 'BUDGETAIRE');

        self::assertSame($expected->treatmentAndPrimes()->toString(), $permanent['treatmentAndPrimes']);
        self::assertSame($expected->retirement()->toString(), $permanent['retirement']);
        self::assertSame($expected->otherContributions()->toString(), $permanent['otherContributions']);
        self::assertSame($expected->rafp()->toString(), $permanent['rafp']);
        self::assertSame($expected->total()->toString(), $permanent['total']);

        // total = somme exacte des 4 termes (recomposition indépendante du moteur).
        $sum = $expected->treatmentAndPrimes()
            ->plus($expected->retirement())
            ->plus($expected->otherContributions())
            ->plus($expected->rafp());
        self::assertSame($sum->toString(), $permanent['total']);
    }

    /**
     * C1.1 (agrégat) : `aggregate.BUDGETAIRE.permanent` = chaque terme × volume, sommé
     * sur les lignes (volumes 2 et 3 → × 5 pour un même profil).
     */
    public function testPermanentAggregateWeightedByVolume(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [
            ['profileRef' => $profile->id, 'volume' => 2, 'recruitmentDate' => self::RECRUITMENT],
            ['profileRef' => $profile->id, 'volume' => 3, 'recruitmentDate' => self::RECRUITMENT],
        ]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent&views=BUDGETAIRE');
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $perAgent = $this->expectedPermanent()->averagePerAgent();
        /** @var array{aggregate: array<string, array{permanent: array<string, string>}>} $body */
        $aggregate = $body['aggregate']['BUDGETAIRE']['permanent'];

        self::assertSame($perAgent->treatmentAndPrimes()->multipliedByIndex(5)->toString(), $aggregate['treatmentAndPrimes']);
        self::assertSame($perAgent->retirement()->multipliedByIndex(5)->toString(), $aggregate['retirement']);
        self::assertSame($perAgent->otherContributions()->multipliedByIndex(5)->toString(), $aggregate['otherContributions']);
        self::assertSame($perAgent->rafp()->multipliedByIndex(5)->toString(), $aggregate['rafp']);
        self::assertSame($perAgent->total()->multipliedByIndex(5)->toString(), $aggregate['total']);
    }

    /**
     * C1.3 : l'échelon terminal (duration_months vide) entre dans la moyenne pondérée
     * du régime permanent avec une durée de 60 mois (via la factory J1). Le coût exposé
     * coïncide donc avec la moyenne 12/12/60 et diffère de la moyenne obtenue si le
     * terminal était pondéré à 12 mois.
     */
    public function testPermanentTerminalDurationWeighting(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent&views=BUDGETAIRE');
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $permanent = $this->permanentBlock($body, 0, 'BUDGETAIRE');

        $date = new \DateTimeImmutable(self::RECRUITMENT);
        $calculator = $this->permanentCalculator();

        $withTerminal60 = $calculator->compute($this->career(60), $date, 1)->averagePerAgent();
        $withTerminal12 = $calculator->compute($this->career(12), $date, 1)->averagePerAgent();

        self::assertSame($withTerminal60->total()->toString(), $permanent['total']);
        self::assertNotSame($withTerminal12->total()->toString(), $permanent['total']);
    }

    /**
     * C1.2 : le régime permanent est view-aware — pour deux vues (BUDGETAIRE,
     * EQUIVALENT_PRIVE), `results[view].permanent` diffère sur le SEUL terme retraite
     * (les 3 autres termes identiques), cohérent avec le mécanisme de vue (#18) appliqué
     * à la moyenne pondérée par échelon. La vue BUDGETAIRE reste égale au coût de base.
     */
    public function testPermanentIsViewAwareOnRetirementOnly(): void
    {
        $profile = $this->seedMultiEchelonProfile([
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent&views=BUDGETAIRE,EQUIVALENT_PRIVE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $budget = $this->permanentBlock($body, 0, 'BUDGETAIRE');
        $private = $this->permanentBlock($body, 0, 'EQUIVALENT_PRIVE');

        // Seul le terme retraite (et donc le total) diffère entre les deux vues.
        self::assertNotSame($budget['retirement'], $private['retirement']);
        self::assertNotSame($budget['total'], $private['total']);
        self::assertSame($budget['treatmentAndPrimes'], $private['treatmentAndPrimes']);
        self::assertSame($budget['otherContributions'], $private['otherContributions']);
        self::assertSame($budget['rafp'], $private['rafp']);

        // La vue BUDGETAIRE reste le coût de base (non-régression C1.1).
        $base = $this->expectedPermanent()->averagePerAgent();
        self::assertSame($base->retirement()->toString(), $budget['retirement']);

        // La vue EQUIVALENT_PRIVE = moyenne pondérée par échelon des coûts view-aware.
        $expectedPrivate = $this->permanentCalculator()
            ->compute($this->career(60), new \DateTimeImmutable(self::RECRUITMENT), 1, new ViewAwareStepCost($this->viewCalculator(), 'EQUIVALENT_PRIVE'))
            ->averagePerAgent();
        self::assertSame($expectedPrivate->retirement()->toString(), $private['retirement']);
        self::assertSame($expectedPrivate->total()->toString(), $private['total']);
    }

    /**
     * C4.2 : `horizons=permanent` sur un profil dont un paramètre daté nécessaire à la
     * valorisation de la carrière manque (point_indice) → 422 (UncomputableView), pas de 500.
     */
    public function testPermanentUnderParameterizedReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-no-point');
        $profile = $this->persistComputableProfile($this->em, $set, omitKeys: ['point_indice']);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent&views=BUDGETAIRE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('BUDGETAIRE', $body['error']);
        self::assertStringContainsString('point_indice', $body['error']);
    }

    private function expectedPermanent(): PermanentRegimeCost
    {
        return $this->permanentCalculator()->compute($this->career(60), new \DateTimeImmutable(self::RECRUITMENT), 1);
    }

    /**
     * Carrière PE/FPE/CAS_CIVIL à 3 échelons : IM 395 (12 mois), IM 420 (12 mois), IM
     * 450 (terminal, `$terminalMonths` mois).
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
     * @param list<array{rank: int, increasedIndex: int, durationMonths: int|null}> $extraEchelons
     */
    private function seedMultiEchelonProfile(array $extraEchelons): Profile
    {
        $set = $this->persistParameterSet($this->em, 'ver-perm');
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
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function permanentBlock(array $body, int $lineIndex, string $view): array
    {
        /** @var array{lines: list<array{results: array<string, array{permanent: array<string, string>}>}>} $body */
        return $body['lines'][$lineIndex]['results'][$view]['permanent'];
    }

    private function permanentCalculator(): PermanentRegimeCalculator
    {
        return new PermanentRegimeCalculator($this->baseCalculator());
    }

    private function baseCalculator(): AnnualCostCalculator
    {
        $calculator = static::getContainer()->get(AnnualCostCalculator::class);
        self::assertInstanceOf(AnnualCostCalculator::class, $calculator);

        return $calculator;
    }

    private function viewCalculator(): CostViewCalculator
    {
        $calculator = static::getContainer()->get(CostViewCalculator::class);
        self::assertInstanceOf(CostViewCalculator::class, $calculator);

        return $calculator;
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
            content: (string) json_encode(['title' => 'Permanent', 'lines' => $lines]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['id'];
        self::assertIsInt($id);

        return $id;
    }
}
