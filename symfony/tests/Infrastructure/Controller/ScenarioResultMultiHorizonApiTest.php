<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Profile;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Non-régression multi-horizon (#66, J4, C3) : cohabitation year1/permanent/cohorte,
 * `year1` strictement inchangé (fenêtres + detail + prorata), `detail=full` limité à
 * year1 (permanent/cohorte sans bloc detail), et clé de cache incluant les horizons
 * (deux sélections d'horizons distinctes ne se télescopent pas).
 */
final class ScenarioResultMultiHorizonApiTest extends WebTestCase
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
     * C3.1 : `horizons=year1,permanent,cohorte&detail=full` → les 3 clés présentes sous
     * la vue ; year1 garde ses 3 fenêtres AVEC bloc detail/prorata ; permanent et cohorte
     * SANS bloc detail (le dépliage reste limité à year1).
     */
    public function testMultiHorizonWithDetailFullUnfoldsYear1Only(): void
    {
        $id = $this->createScenario($this->seedMultiEchelonProfile());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=year1,permanent,cohorte&views=BUDGETAIRE&detail=full');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        /** @var array{lines: list<array{results: array<string, array<string, mixed>>}>} $body */
        $view = $body['lines'][0]['results']['BUDGETAIRE'];

        // Les trois horizons sont présents.
        self::assertArrayHasKey('year1', $view);
        self::assertArrayHasKey('permanent', $view);
        self::assertArrayHasKey('cohorte', $view);

        // year1 : 3 fenêtres, chacune avec un bloc detail + prorata.
        /** @var array{fullYear: array<string, mixed>, year1: array<string, mixed>, schoolYear: array<string, mixed>|null} $year1 */
        $year1 = $view['year1'];
        self::assertSame(['fullYear', 'year1', 'schoolYear'], array_keys($year1));
        /** @var array{detail: array<string, array<string, mixed>>} $fullYear */
        $fullYear = $year1['fullYear'];
        self::assertArrayHasKey('detail', $fullYear);
        self::assertArrayHasKey('prorata', $fullYear['detail']['retirement']);

        // permanent : montant à plat, SANS bloc detail.
        /** @var array<string, mixed> $permanent */
        $permanent = $view['permanent'];
        self::assertArrayNotHasKey('detail', $permanent);
        self::assertSame(['treatmentAndPrimes', 'retirement', 'otherContributions', 'rafp', 'total'], array_keys($permanent));

        // cohorte : { perYear, total }, SANS bloc detail (ni au niveau série, ni par année).
        /** @var array{perYear: list<array<string, mixed>>, total: array<string, mixed>} $cohorte */
        $cohorte = $view['cohorte'];
        self::assertSame(['perYear', 'total'], array_keys($cohorte));
        self::assertArrayNotHasKey('detail', $cohorte['total']);
        foreach ($cohorte['perYear'] as $year) {
            self::assertArrayNotHasKey('detail', $year);
            self::assertSame(['treatmentAndPrimes', 'retirement', 'otherContributions', 'rafp', 'total'], array_keys($year));
        }
    }

    /**
     * year1 strictement inchangé : le bloc `year1` (fenêtres + detail + prorata) est
     * identique qu'il soit demandé seul ou combiné à permanent/cohorte.
     */
    public function testYear1IsIdenticalAloneOrCombined(): void
    {
        $id = $this->createScenario($this->seedMultiEchelonProfile());

        $alone = $this->year1Block($id, 'horizons=year1&views=BUDGETAIRE&detail=full');
        $combined = $this->year1Block($id, 'horizons=year1,permanent,cohorte&views=BUDGETAIRE&detail=full');

        self::assertSame($alone, $combined);
    }

    /**
     * `detail=full` sur `horizons=permanent,cohorte` (sans year1) : ni permanent ni
     * cohorte ne portent de bloc detail (dépliage limité à year1, hors périmètre ici).
     */
    public function testDetailFullDoesNotUnfoldPermanentOrCohort(): void
    {
        $id = $this->createScenario($this->seedMultiEchelonProfile());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?horizons=permanent,cohorte&views=BUDGETAIRE&detail=full');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $raw = (string) $this->client->getResponse()->getContent();
        /** @var array{lines: list<array{results: array<string, array<string, mixed>>}>} $body */
        $body = json_decode($raw, true);
        $view = $body['lines'][0]['results']['BUDGETAIRE'];

        self::assertArrayNotHasKey('year1', $view);
        /** @var array<string, mixed> $permanent */
        $permanent = $view['permanent'];
        self::assertArrayNotHasKey('detail', $permanent);
        /** @var array{perYear: list<array<string, mixed>>, total: array<string, mixed>} $cohorte */
        $cohorte = $view['cohorte'];
        self::assertArrayNotHasKey('detail', $cohorte['total']);
        foreach ($cohorte['perYear'] as $year) {
            self::assertArrayNotHasKey('detail', $year);
        }
    }

    /**
     * Cache par (version, vues, horizons, detail) : deux sélections d'horizons distinctes
     * ont des entrées séparées (aucun télescopage) ; chacune reste servie par la sienne.
     */
    public function testDistinctHorizonSelectionsAreSeparateCacheEntries(): void
    {
        $id = $this->createScenario($this->seedMultiEchelonProfile());
        $base = '/api/scenarios/' . $id . '/result?views=BUDGETAIRE&horizons=';

        // Premier appel de chaque sélection : MISS.
        self::assertSame('MISS', $this->cacheHeader($base . 'year1'));
        self::assertSame('MISS', $this->cacheHeader($base . 'permanent'));
        self::assertSame('MISS', $this->cacheHeader($base . 'cohorte'));
        self::assertSame('MISS', $this->cacheHeader($base . 'year1,permanent'));

        // Chaque sélection reste servie par SA propre entrée (pas d'écrasement croisé).
        self::assertSame('HIT', $this->cacheHeader($base . 'year1'));
        self::assertSame('HIT', $this->cacheHeader($base . 'permanent'));
        self::assertSame('HIT', $this->cacheHeader($base . 'cohorte'));
        self::assertSame('HIT', $this->cacheHeader($base . 'year1,permanent'));

        // L'ordre des horizons est indifférent (clé triée) : même entrée.
        self::assertSame('HIT', $this->cacheHeader($base . 'permanent,year1'));
    }

    /**
     * @return array<string, mixed>
     */
    private function year1Block(int $id, string $query): array
    {
        $this->client->request('GET', '/api/scenarios/' . $id . '/result?' . $query);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{lines: list<array{results: array<string, array{year1: array<string, mixed>}>}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['lines'][0]['results']['BUDGETAIRE']['year1'];
    }

    private function cacheHeader(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $header = $this->client->getResponse()->headers->get('X-Cache');
        self::assertIsString($header);

        return $header;
    }

    /**
     * Profil PE/FPE/CAS_CIVIL à 3 échelons (IM 395 / 420 / 450, terminal) : carrière
     * multi-échelons pour que permanent et cohorte soient non triviaux.
     */
    private function seedMultiEchelonProfile(): Profile
    {
        $set = $this->persistParameterSet($this->em, 'ver-multi');
        $profile = $this->persistComputableProfile($this->em, $set);

        $grille = $profile->entryEchelon->grille;
        foreach ([['rank' => 2, 'im' => 420, 'd' => 12], ['rank' => 3, 'im' => 450, 'd' => null]] as $spec) {
            $echelon = new Echelon();
            $echelon->grille = $grille;
            $echelon->rank = $spec['rank'];
            $echelon->grossIndex = $spec['im'] + 10;
            $echelon->increasedIndex = $spec['im'];
            $echelon->durationMonths = $spec['d'];
            $this->em->persist($echelon);
        }
        $this->em->flush();

        return $profile;
    }

    private function createScenario(Profile $profile): int
    {
        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Multi-horizon',
                'lines' => [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]],
            ]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array{id: int} $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $created['id'];
    }

    private function resultPool(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get('app.cache.scenario_result');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }
}
