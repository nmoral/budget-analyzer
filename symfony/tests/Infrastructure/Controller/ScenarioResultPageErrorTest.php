<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiche de résultat web (#20, revue R1) : une sélection invalide dans l'URL
 * (`?views=` / `?horizons=`) — URL explicitement éditable et rejouable (C8) — ne doit
 * jamais produire une HTTP 500 ni exposer de trace. Le contrôleur attrape
 * `InvalidSelectionException` (vue/horizon inconnu) et `UncomputableViewException`
 * (vue valide non valorisable) et rend une page d'erreur lisible, en miroir du 404
 * « scénario inconnu » (C1).
 */
final class ScenarioResultPageErrorTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private const string VERSION = 'ver-page-err';
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
     * R1 : `?views=INVALID` (vue inconnue → InvalidSelectionException) → page lisible
     * (statut d'erreur, pas 500, ni « Stack Trace » ni nom d'exception dans le HTML).
     */
    public function testUnknownViewRendersReadableErrorPage(): void
    {
        $id = $this->seedComputableScenario();

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?views=INVALID');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame(500, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('[data-testid="error-title"]')->count());
        self::assertStringNotContainsString('Stack Trace', (string) $response->getContent());
        self::assertStringNotContainsString('InvalidSelectionException', (string) $response->getContent());
    }

    /**
     * R1 : `?horizons=bar` (horizon inconnu → InvalidSelectionException) → idem.
     */
    public function testUnknownHorizonRendersReadableErrorPage(): void
    {
        $id = $this->seedComputableScenario();

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?horizons=bar');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame(500, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('[data-testid="error-title"]')->count());
        self::assertStringNotContainsString('Stack Trace', (string) $response->getContent());
        self::assertStringNotContainsString('InvalidSelectionException', (string) $response->getContent());
    }

    /**
     * R1 : vue valide (BUDGETAIRE) mais non valorisable (jeu sous-paramétré →
     * UncomputableViewException) → page lisible (pas 500, pas de trace).
     */
    public function testUncomputableViewRendersReadableErrorPage(): void
    {
        $id = $this->seedUncomputableScenario();

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame(500, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('[data-testid="error-title"]')->count());
        self::assertStringNotContainsString('Stack Trace', (string) $response->getContent());
        self::assertStringNotContainsString('UncomputableViewException', (string) $response->getContent());
    }

    /**
     * Non-régression : une sélection valide répond toujours 200.
     */
    public function testValidSelectionStillReturns200(): void
    {
        $id = $this->seedComputableScenario();

        $this->client->request('GET', '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function seedComputableScenario(): int
    {
        $set = $this->persistParameterSet($this->em, self::VERSION);
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();

        return $this->persistScenario($set, $profile);
    }

    private function seedUncomputableScenario(): int
    {
        $set = $this->persistParameterSet($this->em, self::VERSION . '-uncomputable');
        // Vue valide (BUDGETAIRE) mais non valorisable : taux de primes du corps omis.
        $profile = $this->persistComputableProfile($this->em, $set, omitPrimeRate: true);
        $this->em->flush();

        return $this->persistScenario($set, $profile);
    }

    private function persistScenario(ParameterSet $set, Profile $profile): int
    {
        $scenario = new Scenario();
        $scenario->title = 'Scénario de test';
        $scenario->provenance = 'simulation';
        $scenario->parameterSet = $set;
        $this->em->persist($scenario);

        $line = new ScenarioLine();
        $line->scenario = $scenario;
        $line->profile = $profile;
        $line->volume = 1;
        $line->recruitmentDate = new \DateTimeImmutable(self::RECRUITMENT);
        $line->spreadYears = 0;
        $this->em->persist($line);

        $this->em->flush();
        self::assertNotNull($scenario->id);

        return $scenario->id;
    }
}
