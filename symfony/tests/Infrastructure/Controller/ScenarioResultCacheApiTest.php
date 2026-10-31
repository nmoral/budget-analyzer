<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Entity\Profile;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cache du résultat (#19, J3) : C5 (HIT/MISS par clé = scénario+version+sélection) et
 * C6 (invalidation par version, purge au `app:import`).
 *
 * Le noyau n'est pas redémarré entre requêtes (`disableReboot`) pour que le pool mémoire
 * de test persiste d'un appel à l'autre ; chaque cas repart d'un noyau neuf (isolé).
 */
final class ScenarioResultCacheApiTest extends WebTestCase
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
     * C5.1 : deux appels identiques → corps identique, X-Cache MISS puis HIT.
     */
    public function testSecondIdenticalCallIsServedFromCache(): void
    {
        $profile = $this->seed('ver-c5');
        $id = $this->createScenario($profile);
        $url = '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE';

        $firstHeader = $this->requestResult($url);
        $firstBody = (string) $this->client->getResponse()->getContent();
        self::assertSame('MISS', $firstHeader);

        $secondHeader = $this->requestResult($url);
        $secondBody = (string) $this->client->getResponse()->getContent();
        self::assertSame('HIT', $secondHeader);
        self::assertSame($firstBody, $secondBody);
    }

    /**
     * C5.2 : même scénario+version mais sélection différente → entrées distinctes
     * (MISS au premier appel de chaque vue).
     */
    public function testDifferentSelectionIsADistinctCacheEntry(): void
    {
        $profile = $this->seed('ver-c5b');
        $id = $this->createScenario($profile);

        $budgetaryMiss = $this->requestResult('/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        self::assertSame('MISS', $budgetaryMiss);

        $privateMiss = $this->requestResult('/api/scenarios/' . $id . '/result?views=EQUIVALENT_PRIVE');
        self::assertSame('MISS', $privateMiss);

        // La vue budgétaire reste servie par le cache (entrée non écrasée).
        $budgetaryHit = $this->requestResult('/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        self::assertSame('HIT', $budgetaryHit);
    }

    /**
     * R1 (J5) : une vue répétée (`views=BUDGETAIRE,BUDGETAIRE`) produit la même entrée
     * de cache que la version non répétée → servie par le cache (HIT).
     */
    public function testRepeatedViewSharesCacheEntryWithNonRepeated(): void
    {
        $profile = $this->seed('ver-r1');
        $id = $this->createScenario($profile);

        $firstHeader = $this->requestResult('/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE');
        self::assertSame('MISS', $firstHeader);

        $repeatedHeader = $this->requestResult('/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE,BUDGETAIRE');
        self::assertSame('HIT', $repeatedHeader);
    }

    /**
     * C6.1 : deux versions aux taux retraite distincts → résultats distincts, aucun
     * cache croisé (chaque version a sa propre clé).
     */
    public function testDifferentVersionYieldsDistinctResultWithoutCrossCache(): void
    {
        $setA = $this->persistParameterSet($this->em, 'ver-a', '2026-01-01');
        $profile = $this->persistComputableProfile($this->em, $setA);
        $setB = $this->persistParameterSet($this->em, 'ver-b', '2026-02-01');
        // Même corps, taux CAS civil différent (50 % vs 82.28 %).
        $this->persistEngineParameters($this->em, $setB, $profile->body, '50.00');
        $this->em->flush();

        $idA = $this->createScenario($profile, 'ver-a');
        $idB = $this->createScenario($profile, 'ver-b');

        $retirementA = $this->retirement($idA);
        $retirementB = $this->retirement($idB);

        self::assertNotSame($retirementA, $retirementB);
    }

    /**
     * C6.2 : après `app:import`, un résultat précédemment en cache est recalculé (MISS).
     */
    public function testImportPurgesCachedResults(): void
    {
        // Corps hors des données réelles (PE/ISG) pour éviter tout conflit à l'import.
        $set = $this->persistParameterSet($this->em, 'ver-c62');
        $profile = $this->persistComputableProfile($this->em, $set, 'scolaire', 'TESTCORPS');
        $this->em->flush();
        $id = $this->createScenario($profile);
        $url = '/api/scenarios/' . $id . '/result?horizons=year1&views=BUDGETAIRE';

        $beforeMiss = $this->requestResult($url);
        self::assertSame('MISS', $beforeMiss);
        $beforeHit = $this->requestResult($url);
        self::assertSame('HIT', $beforeHit);

        $this->runImport();

        $afterImport = $this->requestResult($url);
        self::assertSame('MISS', $afterImport);
    }

    private function seed(string $versionId): Profile
    {
        $set = $this->persistParameterSet($this->em, $versionId);
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();

        return $profile;
    }

    private function createScenario(Profile $profile, ?string $parametersVersion = null): int
    {
        $payload = [
            'title' => 'Cache',
            'lines' => [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]],
        ];
        if ($parametersVersion !== null) {
            $payload['parametersVersion'] = $parametersVersion;
        }

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array{id: int} $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $created['id'];
    }

    private function retirement(int $scenarioId): string
    {
        $this->client->request('GET', '/api/scenarios/' . $scenarioId . '/result?views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{lines: list<array{results: array{BUDGETAIRE: array{year1: array{fullYear: array{retirement: string}}}}}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['lines'][0]['results']['BUDGETAIRE']['year1']['fullYear']['retirement'];
    }

    private function requestResult(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $header = $this->client->getResponse()->headers->get('X-Cache');
        self::assertIsString($header);

        return $header;
    }

    private function runImport(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('app:import'));
        $exitCode = $tester->execute(['commit' => 'c62-import']);
        self::assertSame(0, $exitCode);
    }
}
