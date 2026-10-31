<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mode transparence `?detail=full` du résultat (#65, J3) : dépliage par nombre
 * (C1.1), non-régression sans le flag (C1.2), source en clair complète (C2.1),
 * hypothèses nommées (C3.1), clé de cache par `detail` et invariants (C4).
 */
final class ScenarioResultDetailApiTest extends WebTestCase
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

    /** C1.2 : sans le flag, aucune clé `detail` ni `meta` (réponse légère inchangée). */
    public function testWithoutFlagResponseHasNoTransparencyBlocks(): void
    {
        $id = $this->scenario();

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertStringNotContainsString('"detail"', $raw);
        self::assertStringNotContainsString('"meta"', $raw);

        /** @var array{lines: list<array{results: array<string, array{year1: array{fullYear: array<string, mixed>}}>}>} $body */
        $body = json_decode($raw, true);
        $window = $body['lines'][0]['results']['BUDGETAIRE']['year1']['fullYear'];
        self::assertSame(
            ['treatmentAndPrimes', 'retirement', 'otherContributions', 'rafp', 'total'],
            array_keys($window),
        );
    }

    /** C1.1 + C2.1 : avec le flag, chaque terme porte un bloc detail et sa source en clair. */
    public function testDetailFullUnfoldsEachTermWithSources(): void
    {
        $id = $this->scenario();

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE&detail=full');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{lines: list<array{results: array<string, array{year1: array<string, array<string, mixed>>}>}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $full = $body['lines'][0]['results']['BUDGETAIRE']['year1']['fullYear'];
        /** @var array<string, array<string, mixed>> $detail */
        $detail = $full['detail'];
        self::assertSame(
            ['treatmentAndPrimes', 'retirement', 'otherContributions', 'rafp'],
            array_keys($detail),
        );

        $retirement = $detail['retirement'];
        self::assertSame($full['retirement'], $retirement['value']);
        self::assertSame('TIB·r_retraite', $retirement['formula']);

        /** @var list<array<string, string>> $inputs */
        $inputs = $retirement['inputs'];
        self::assertSame('taux_cas_civil', $inputs[0]['key']);
        // Valeur relue depuis la colonne DECIMAL(14,6) : échelle 6 (« 82.280000 »).
        self::assertSame('82.280000', $inputs[0]['value']);
        self::assertSame('UNIT', $inputs[0]['unit']);
        self::assertSame('2020-01-01', $inputs[0]['effectiveDate']);

        /** @var list<array<string, string>> $sources */
        $sources = $retirement['sources'];
        self::assertNotEmpty($sources);
        foreach (['code', 'publisher', 'label', 'edition', 'locator', 'url', 'retrievedAt'] as $field) {
            self::assertArrayHasKey($field, $sources[0]);
            self::assertNotSame('', trim($sources[0][$field]), sprintf('champ source %s vide', $field));
        }
    }

    /** C3.1 : hypothèses nommées — métadonnées de vue ACTUARIELLE + hypothèses structurelles. */
    public function testDetailFullNamesAssumptions(): void
    {
        $id = $this->scenario();

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=ACTUARIELLE&detail=full');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{meta: array{views: array<string, array{definition: string, question: string, assumptions: list<string>, sources: list<string>}>, structuralAssumptions: list<string>}} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $actuarial = $body['meta']['views']['ACTUARIELLE'];
        self::assertNotSame('', $actuarial['definition']);
        self::assertNotSame('', $actuarial['question']);
        self::assertCount(2, $actuarial['assumptions']);
        self::assertStringContainsString('annuité', implode(' ', $actuarial['assumptions']));
        self::assertStringContainsString('actuariel', implode(' ', $actuarial['assumptions']));

        $structural = $body['meta']['structuralAssumptions'];
        $joined = implode(' ', $structural);
        self::assertStringContainsString('point d\'indice', $joined);
        self::assertStringContainsString('fallback', $joined);
        self::assertStringContainsString('stationnaire', $joined);
    }

    /** C4 : la réponse detail=full a une entrée de cache distincte de la réponse légère. */
    public function testDetailIsADistinctCacheEntry(): void
    {
        $id = $this->scenario();
        $light = '/api/scenarios/' . $id . '/result?views=BUDGETAIRE';
        $full = $light . '&detail=full';

        self::assertSame('MISS', $this->cacheHeader($light));
        self::assertSame('HIT', $this->cacheHeader($light));

        // La variante detail=full ne doit pas être servie par l'entrée légère.
        self::assertSame('MISS', $this->cacheHeader($full));
        self::assertSame('HIT', $this->cacheHeader($full));

        // Et la variante légère reste servie par sa propre entrée.
        self::assertSame('HIT', $this->cacheHeader($light));
    }

    /** C4 : total = somme exacte des 4 termes dépliés (au niveau réponse). */
    public function testTotalEqualsSumOfTheFourTermsInDetail(): void
    {
        $id = $this->scenario();

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE&detail=full');
        /** @var array{lines: list<array{results: array<string, array{year1: array{fullYear: array<string, mixed>}}>}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $full = $body['lines'][0]['results']['BUDGETAIRE']['year1']['fullYear'];

        $sum = bcadd(
            bcadd($this->num($full['treatmentAndPrimes']), $this->num($full['retirement']), 6),
            bcadd($this->num($full['otherContributions']), $this->num($full['rafp']), 6),
            6,
        );
        self::assertSame($this->num($full['total']), $sum);
    }

    /**
     * @return numeric-string
     */
    private function num(mixed $value): string
    {
        self::assertIsString($value);
        self::assertTrue(is_numeric($value));
        /** @var numeric-string $value */

        return $value;
    }

    /** C4 : codes d'erreur inchangés avec le flag (404 scénario inconnu, 422 vue inconnue). */
    public function testErrorStatusCodesUnchangedWithFlag(): void
    {
        $id = $this->scenario();

        $this->client->request('GET', '/api/scenarios/999999/result?detail=full');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=INCONNU&detail=full');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    private function scenario(): int
    {
        $set = $this->persistParameterSet($this->em, 'ver-detail');
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Transparence',
                'lines' => [['profileRef' => $profile->id, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]],
            ]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array{id: int} $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $created['id'];
    }

    private function cacheHeader(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $header = $this->client->getResponse()->headers->get('X-Cache');
        self::assertIsString($header);

        return $header;
    }
}
