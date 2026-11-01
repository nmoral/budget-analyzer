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
 * Fiche de résultat web (#20, J5) : URL stable rejouable (C8).
 *
 * C8 : l'URL encode version des paramètres + vues + horizons (+ detail) ; rejouer la
 *      même URL redonne la même fiche (version épinglée si présente dans l'URL, sinon
 *      le jeu épinglé du scénario — comportement actuel —, affichée) ; les vues/horizons
 *      rendus découlent de l'URL.
 */
final class ScenarioResultPageUrlTest extends WebTestCase
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
     * C8.1 : ouvrir `?parametersVersion=<v>&views=BUDGETAIRE&horizons=year1,permanent`,
     * copier l'URL, rouvrir → fiche identique (mêmes vues/horizons/version). La version
     * épinglée dans l'URL pilote le calcul et est affichée : le scénario est épinglé sur
     * `ver-a`, mais l'URL épingle `ver-b` (second jeu valorisable) ⇒ la fiche affiche
     * `ver-b`. Les vues/horizons rendus découlent de l'URL (cohorte absent).
     */
    public function testPinnedVersionInUrlDrivesComputationAndIsDisplayed(): void
    {
        $id = $this->seedScenarioWithSecondVersion('ver-a', 'ver-b');
        $url = '/scenarios/' . $id . '?parametersVersion=ver-b&views=BUDGETAIRE&horizons=year1,permanent';

        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // La version épinglée dans l'URL est celle affichée (et non celle du scénario).
        self::assertSame('ver-b', trim($crawler->filter('[data-testid="parameters-version"]')->text()));

        // Les vues/horizons rendus découlent de l'URL.
        $views = $crawler->filter('[data-testid="aggregate-view"]')->each(static fn ($n): string => (string) $n->attr('data-view'));
        self::assertSame(['BUDGETAIRE'], $views);
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-year1"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-permanent"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="horizon-cohorte"]')->count());

        // Rejouer la même URL redonne exactement la même fiche (rendu déterministe).
        $first = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
    }

    /**
     * C8 : sans `parametersVersion` dans l'URL, la fiche utilise le jeu épinglé du
     * scénario (comportement actuel) et affiche sa version — même si un jeu plus récent
     * existe (le scénario reste sur `ver-a`, pas sur `ver-b`).
     */
    public function testVersionAbsentUsesScenarioSetAndDisplaysIt(): void
    {
        $id = $this->seedScenarioWithSecondVersion('ver-a', 'ver-b');

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame('ver-a', trim($crawler->filter('[data-testid="parameters-version"]')->text()));
    }

    /**
     * C8 : une version inconnue dans l'URL n'est pas « inventée » — la fiche retombe sur
     * le jeu épinglé du scénario et affiche la version réellement utilisée (pas le code
     * demandé), tout en restant déterministe (200, pas de 500).
     */
    public function testUnknownUrlVersionFallsBackToScenarioSet(): void
    {
        $id = $this->seedScenarioWithSecondVersion('ver-real', 'ver-b');

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?parametersVersion=does-not-exist&views=BUDGETAIRE&horizons=year1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame('ver-real', trim($crawler->filter('[data-testid="parameters-version"]')->text()));
    }

    /**
     * Scénario épinglé sur `$scenarioVersion`, plus un second jeu de paramètres
     * `$extraVersion` (importé plus tard) portant les paramètres datés valorisables pour
     * le MÊME corps : la fiche peut donc calculer indifféremment contre l'un ou l'autre.
     */
    private function seedScenarioWithSecondVersion(string $scenarioVersion, string $extraVersion): int
    {
        $set = $this->persistParameterSet($this->em, $scenarioVersion);
        $profile = $this->persistComputableProfile($this->em, $set);

        // Second jeu, importé après le premier : paramètres valorisables pour le même corps.
        $extra = $this->persistParameterSet($this->em, $extraVersion, '2026-06-01');
        $this->persistEngineParameters($this->em, $extra, $profile->body);
        $this->em->flush();

        $scenario = new Scenario();
        $scenario->title = 'URL rejouable';
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
