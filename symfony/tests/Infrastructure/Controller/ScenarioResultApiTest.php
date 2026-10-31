<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\CostViewCalculator;
use App\Infrastructure\Entity\Profile;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Résultat année 1 par vue (#19, J2) : C3 (par ligne + agrégat), C4 (version
 * épinglée) et C7 (vue/horizon inconnus → 422).
 */
final class ScenarioResultApiTest extends WebTestCase
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

    private function resultPool(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get('app.cache.scenario_result');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    /**
     * C3.1 : horizon année 1 budgétaire → fullYear = moteur de base ; year1 proraté
     * sept→déc (4 mois) ; schoolYear présent (calendrier scolaire, sept→août = 12).
     */
    public function testBudgetaryYear1Result(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        $expected = $this->baseCalculator()->compute($this->situation(), new \DateTimeImmutable(self::RECRUITMENT));

        $window = $this->window($body, 0, 'BUDGETAIRE');
        self::assertSame($expected->total()->toString(), $window['fullYear']['total']);
        self::assertSame($expected->retirement()->toString(), $window['fullYear']['retirement']);
        self::assertSame($this->prorated($expected, 4)->total()->toString(), $window['year1']['total']);
        self::assertNotNull($window['schoolYear']);
        self::assertSame($window['fullYear']['total'], $window['schoolYear']['total']);
    }

    /**
     * C3.2 : vue EQUIVALENT_PRIVE → même structure ; retraite = TIB×28 %, les 3 autres
     * termes identiques à la vue budgétaire (base commune).
     */
    public function testPrivateEquivalentViewResult(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=EQUIVALENT_PRIVE,BUDGETAIRE');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        $expectedPrivate = $this->viewCalculator()->compute($this->situation(), new \DateTimeImmutable(self::RECRUITMENT), 'EQUIVALENT_PRIVE');

        $private = $this->window($body, 0, 'EQUIVALENT_PRIVE');
        $budget = $this->window($body, 0, 'BUDGETAIRE');
        self::assertSame($expectedPrivate->retirement()->toString(), $private['fullYear']['retirement']);
        self::assertNotSame($budget['fullYear']['retirement'], $private['fullYear']['retirement']);
        self::assertSame($budget['fullYear']['treatmentAndPrimes'], $private['fullYear']['treatmentAndPrimes']);
        self::assertSame($budget['fullYear']['otherContributions'], $private['fullYear']['otherContributions']);
        self::assertSame($budget['fullYear']['rafp'], $private['fullYear']['rafp']);
    }

    /**
     * C3.3 : 2 lignes (volumes 2 et 3) → agrégat = Σ(résultat_ligne × volume).
     */
    public function testMultiLineAggregate(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [
            ['profileRef' => $profile->id, 'volume' => 2, 'recruitmentDate' => self::RECRUITMENT],
            ['profileRef' => $profile->id, 'volume' => 3, 'recruitmentDate' => self::RECRUITMENT],
        ]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE');
        /** @var array{aggregate: array<string, array{year1: array{year1: array<string, string>}}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $expected = $this->baseCalculator()->compute($this->situation(), new \DateTimeImmutable(self::RECRUITMENT));
        $lineYear1Total = $this->prorated($expected, 4)->total();
        $aggregateExpected = $lineYear1Total->multipliedByIndex(2)->plus($lineYear1Total->multipliedByIndex(3));

        $aggregateWindow = $body['aggregate']['BUDGETAIRE']['year1'];
        self::assertSame($aggregateExpected->toString(), $aggregateWindow['year1']['total']);
    }

    /**
     * R1 (J5) : une vue répétée dans la requête (`views=BUDGETAIRE,BUDGETAIRE`) ne doit
     * pas doubler l'agrégat. L'agrégat reste Σ(ligne × volume) et la liste `views`
     * exposée est dédupliquée.
     */
    public function testRepeatedViewDoesNotDoubleAggregate(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [
            ['profileRef' => $profile->id, 'volume' => 2, 'recruitmentDate' => self::RECRUITMENT],
            ['profileRef' => $profile->id, 'volume' => 3, 'recruitmentDate' => self::RECRUITMENT],
        ]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE,BUDGETAIRE');
        /** @var array{views: list<string>, aggregate: array<string, array{year1: array{year1: array<string, string>}}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $expected = $this->baseCalculator()->compute($this->situation(), new \DateTimeImmutable(self::RECRUITMENT));
        $lineYear1Total = $this->prorated($expected, 4)->total();
        $aggregateExpected = $lineYear1Total->multipliedByIndex(2)->plus($lineYear1Total->multipliedByIndex(3));

        self::assertSame(['BUDGETAIRE'], $body['views']);
        self::assertSame($aggregateExpected->toString(), $body['aggregate']['BUDGETAIRE']['year1']['year1']['total']);
    }

    /**
     * C4.1 : deux appels identiques → `parametersVersion` identique et valeurs
     * identiques (calcul contre la version épinglée).
     */
    public function testReplayabilityIsStable(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-pinned');
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE');
        $first = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE');
        $second = (string) $this->client->getResponse()->getContent();

        self::assertSame($first, $second);
        /** @var array<string, mixed> $body */
        $body = json_decode($first, true);
        self::assertSame('ver-pinned', $body['parametersVersion']);
    }

    public function testUnknownViewReturns422(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=INCONNU');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownHorizonReturns422(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=cohorte');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testResultForUnknownScenarioReturns404(): void
    {
        $this->client->request('GET', '/api/scenarios/999999/result');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * C1.1 (#58) : vue EQUILIBRE_TECHNIQUE valide mais jeu sans `taux_equilibre_cas_civil`
     * (non seedé par les fixtures) → 422 maîtrisé (jamais 500), message nommant la vue,
     * la clé de paramètre manquante et la date couverte (MissingDatedRateException).
     */
    public function testUnderParameterizedTechnicalBalanceReturns422(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=EQUILIBRE_TECHNIQUE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('EQUILIBRE_TECHNIQUE', $body['error']);
        self::assertStringContainsString('taux_equilibre_cas_civil', $body['error']);
        self::assertStringContainsString(self::RECRUITMENT, $body['error']);
    }

    /**
     * C1.2 (#58) : vue EQUIVALENT_PRIVE valide mais jeu sans `taux_employeur_prive`
     * → 422, message nommant la vue et la clé manquante (MissingDatedRateException).
     */
    public function testUnderParameterizedPrivateEquivalentReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-no-prive');
        $profile = $this->persistComputableProfile($this->em, $set, omitKeys: ['taux_employeur_prive']);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=EQUIVALENT_PRIVE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('EQUIVALENT_PRIVE', $body['error']);
        self::assertStringContainsString('taux_employeur_prive', $body['error']);
    }

    /**
     * C1 (#58) : couverture de MissingDatedAmountException — jeu sans `point_indice`
     * (montant daté) → même vue BUDGETAIRE non valorisable → 422 nommant vue et clé.
     */
    public function testUnderParameterizedMissingAmountReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-no-point');
        $profile = $this->persistComputableProfile($this->em, $set, omitKeys: ['point_indice']);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('BUDGETAIRE', $body['error']);
        self::assertStringContainsString('point_indice', $body['error']);
    }

    /**
     * C1 (#58) : couverture de MissingPrimeRateException — jeu sans taux de primes du
     * corps → vue BUDGETAIRE non valorisable → 422 nommant la vue (jamais 500).
     */
    public function testUnderParameterizedMissingPrimeRateReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-no-prime');
        $profile = $this->persistComputableProfile($this->em, $set, omitPrimeRate: true);
        $this->em->flush();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('BUDGETAIRE', $body['error']);
    }

    /**
     * C2.1 (#58) : sélection mixte (BUDGETAIRE calculable + EQUILIBRE_TECHNIQUE non) →
     * tout-ou-rien : 422 global nommant la vue fautive ; BUDGETAIRE seul → 200.
     */
    public function testMixedSelectionFailsGloballyNamingFaultyView(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE,EQUILIBRE_TECHNIQUE');
        $mixed = $this->client->getResponse();
        self::assertSame(422, $mixed->getStatusCode());
        /** @var array{error: string} $body */
        $body = json_decode((string) $mixed->getContent(), true);
        self::assertStringContainsString('EQUILIBRE_TECHNIQUE', $body['error']);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * C2.2 (#58) : l'erreur de vue sous-paramétrée n'est pas mise en cache (cache non
     * empoisonné) : 2 appels identiques → 2× 422 ; une vue calculable ultérieure → 200.
     */
    public function testUnderParameterizedErrorIsNotCached(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=EQUILIBRE_TECHNIQUE');
        $first = $this->client->getResponse();
        self::assertSame(422, $first->getStatusCode());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=EQUILIBRE_TECHNIQUE');
        $second = $this->client->getResponse();
        self::assertSame(422, $second->getStatusCode());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        $computable = $this->client->getResponse();
        self::assertSame(200, $computable->getStatusCode());
    }

    /**
     * C3.1 (#58) : non-régression du contrat d'erreur — vue paramétrée → 200 ; vue
     * inconnue → 422 (InvalidSelectionException inchangé) ; scénario inconnu → 404.
     */
    public function testNonRegressionStatusCodes(): void
    {
        $profile = $this->seedComputable();
        $id = $this->createScenario($profile, [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]]);

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=INCONNU');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/scenarios/999999/result');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function seedComputable(): Profile
    {
        $set = $this->persistParameterSet($this->em, 'ver-result');
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();

        return $profile;
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
            content: (string) json_encode(['title' => 'Résultat', 'lines' => $lines]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['id'];
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{fullYear: array<string, string>, year1: array<string, string>, schoolYear: array<string, string>|null}
     */
    private function window(array $body, int $lineIndex, string $view): array
    {
        /** @var array{lines: list<array{results: array<string, array{year1: array{fullYear: array<string, string>, year1: array<string, string>, schoolYear: array<string, string>|null}}>}>} $body */
        return $body['lines'][$lineIndex]['results'][$view]['year1'];
    }

    private function prorated(AnnualCost $cost, int $months): AnnualCost
    {
        return new AnnualCost(
            $cost->treatmentAndPrimes()->proratedByMonths($months),
            $cost->retirement()->proratedByMonths($months),
            $cost->otherContributions()->proratedByMonths($months),
            $cost->rafp()->proratedByMonths($months),
        );
    }

    private function situation(): AgentSituation
    {
        return new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');
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
}
