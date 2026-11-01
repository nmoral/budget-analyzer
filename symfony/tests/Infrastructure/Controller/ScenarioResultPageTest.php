<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiche de résultat web (#20, J1) : socle front Twig servi par
 * `GET /scenarios/{id}` (contrôleur `App\Infrastructure\Controller`), rendu
 * server-side via `ScenarioResultCalculator`.
 *
 * C1.1 : page HTML 200 affichant titre + version des paramètres.
 * C1.2 : scénario inconnu → 404 lisible (pas de stack trace) ; `{id}` non
 *        numérique → route non appariée → 404.
 */
final class ScenarioResultPageTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private const string TITLE = 'Recrutement de professeurs des écoles';
    private const string VERSION = 'ver-page';
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
     * C1.1 : `GET /scenarios/{id}` sur un scénario seedé → HTTP 200, page HTML
     * affichant le titre du scénario et la version du jeu de paramètres.
     */
    public function testExistingScenarioRendersHtmlPage(): void
    {
        $id = $this->seedScenario();

        $crawler = $this->client->request('GET', '/scenarios/' . $id);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        self::assertSame(self::TITLE, trim($crawler->filter('[data-testid="scenario-title"]')->text()));
        self::assertSame(self::VERSION, trim($crawler->filter('[data-testid="parameters-version"]')->text()));
    }

    /**
     * C1.2 : scénario inconnu → HTTP 404, page d'erreur lisible, SANS stack trace.
     */
    public function testUnknownScenarioRendersReadable404(): void
    {
        $crawler = $this->client->request('GET', '/scenarios/999999');
        $response = $this->client->getResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('[data-testid="error-title"]')->count());
        self::assertStringNotContainsString('Stack Trace', (string) $response->getContent());
        self::assertStringNotContainsString('NotFoundHttpException', (string) $response->getContent());
    }

    /**
     * C1.2 : `{id}` non numérique → contrainte de route `\d+` non appariée → 404.
     */
    public function testNonNumericIdReturns404(): void
    {
        $this->client->request('GET', '/scenarios/abc');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function seedScenario(): int
    {
        $set = $this->persistParameterSet($this->em, self::VERSION);
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();

        $scenario = new Scenario();
        $scenario->title = self::TITLE;
        $scenario->provenance = 'simulation';
        $scenario->parameterSet = $set;
        $this->em->persist($scenario);

        $this->em->persist($this->line($scenario, $profile));
        $this->em->flush();

        self::assertNotNull($scenario->id);

        return $scenario->id;
    }

    private function line(Scenario $scenario, Profile $profile): ScenarioLine
    {
        $line = new ScenarioLine();
        $line->scenario = $scenario;
        $line->profile = $profile;
        $line->volume = 1;
        $line->recruitmentDate = new \DateTimeImmutable(self::RECRUITMENT);
        $line->spreadYears = 0;

        return $line;
    }
}
