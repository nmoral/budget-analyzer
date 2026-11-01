<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Cost\ScenarioResultCalculator;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiche de résultat web (#20, J4) : règles de transparence sur les horizons allégés.
 *
 * C4 : permanent/cohorte allégés — montants + hypothèses de vue (définition, question,
 *      hypothèses, sources de méthode) + hypothèses structurelles nommées + mention que
 *      le dépliage par nombre est limité à l'année 1 ; aucun bloc détail par nombre.
 * C5 : courbe de cohorte — un SVG inline (server-side) par vue, polyline tracée depuis
 *      `perYear` (un point par année), extrêmes cohérents avec le tableau cohorte.
 * C6 : la fiche nomme toutes les hypothèses appliquées (structurelles + de vue) et
 *      affiche la version du jeu de paramètres (`parametersVersion`).
 */
final class ScenarioResultPageTransparencyTest extends WebTestCase
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
     * C4.1 : `horizons=permanent,cohorte` → montants affichés + hypothèses (vue +
     * structurelles) nommées + mention « dépliage par nombre limité à l'année 1 » ;
     * aucun bloc détail par nombre.
     */
    public function testPermanentAndCohortLightWithAssumptionsAndNotice(): void
    {
        $id = $this->seedScenario('ver-c4', []);

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?horizons=permanent,cohorte');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Montants affichés sur les deux horizons allégés.
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-permanent"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-cohorte"]')->count());

        // Hypothèses de vue nommées : définition, question, sources de méthode (BUDGETAIRE).
        $viewBlock = $crawler->filter('[data-testid="view-assumptions"][data-view="BUDGETAIRE"]');
        self::assertSame(1, $viewBlock->count());
        self::assertNotSame('', trim($viewBlock->filter('[data-testid="view-definition"]')->text()));
        self::assertNotSame('', trim($viewBlock->filter('[data-testid="view-question"]')->text()));
        self::assertGreaterThanOrEqual(1, $viewBlock->filter('[data-testid="view-method-source"]')->count());

        // Hypothèses structurelles nommées.
        self::assertGreaterThanOrEqual(3, $crawler->filter('[data-testid="structural-assumption"]')->count());

        // Mention explicite que le dépliage par nombre est limité à l'année 1.
        $notices = $crawler->filter('[data-testid="year1-only-notice"]');
        self::assertGreaterThanOrEqual(1, $notices->count());
        self::assertStringContainsString("limité à l'année 1", $notices->first()->text());

        // Aucun dépliage par nombre : pas de bloc détail (ni toggle) sur la page.
        self::assertSame(0, $crawler->filter('[data-testid="term-detail"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="term-toggle"]')->count());
    }

    /**
     * C5.1 : `horizons=cohorte` sur un profil à N années → un `<svg>` par vue avec une
     * polyline à N points, cohérente avec `perYear` du tableau (même nombre de points,
     * extrêmes = min/max des totaux de la série).
     */
    public function testCohortCurveHasOnePointPerYearAndConsistentExtremes(): void
    {
        // Profil multi-échelons : 4 échelons non terminaux + terminal ⇒ série > 1 an.
        $id = $this->seedScenario('ver-c5', [
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => 12],
            ['rank' => 4, 'increasedIndex' => 480, 'durationMonths' => 12],
            ['rank' => 5, 'increasedIndex' => 510, 'durationMonths' => null],
        ]);

        $scenario = $this->em->find(Scenario::class, $id) ?? throw new \LogicException('scénario introuvable');
        $result = $this->calculator()->compute($scenario, ['cohorte'], ['BUDGETAIRE']);
        /** @var array{lines: list<array{results: array<string, array{cohorte: array{perYear: list<array<string, numeric-string>>}}>}>} $result */
        $perYear = $result['lines'][0]['results']['BUDGETAIRE']['cohorte']['perYear'];
        $expectedCount = \count($perYear);
        self::assertGreaterThan(1, $expectedCount, 'le profil doit produire plusieurs années');

        $totals = array_map(static fn (array $year): string => $year['total'], $perYear);
        $expectedMin = $totals[0];
        $expectedMax = $totals[0];
        foreach ($totals as $total) {
            $expectedMin = bccomp($total, $expectedMin, 6) < 0 ? $total : $expectedMin;
            $expectedMax = bccomp($total, $expectedMax, 6) > 0 ? $total : $expectedMax;
        }

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?horizons=cohorte&views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $svg = $crawler->filter('[data-testid="scenario-line"] [data-testid="horizon-cohorte"] [data-testid="cohort-curve"]');
        self::assertSame(1, $svg->count());

        // Même nombre de points que la série cohorte.
        $points = trim((string) $svg->filter('[data-testid="cohort-curve-line"]')->attr('points'));
        self::assertCount($expectedCount, explode(' ', $points));

        // Extrêmes cohérents avec le tableau cohorte (min/max des totaux de perYear).
        self::assertSame($expectedMin, $svg->attr('data-min'));
        self::assertSame($expectedMax, $svg->attr('data-max'));
    }

    /**
     * C5 : un SVG par vue affichée (deux vues demandées ⇒ deux courbes sur la ligne).
     */
    public function testOneCohortCurvePerDisplayedView(): void
    {
        $id = $this->seedScenario('ver-c5-views', [
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?horizons=cohorte&views=BUDGETAIRE,EQUIVALENT_PRIVE',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $views = $crawler
            ->filter('[data-testid="scenario-line"] [data-testid="cohort-curve"]')
            ->each(static fn ($node): string => (string) $node->attr('data-view'));
        self::assertSame(['BUDGETAIRE', 'EQUIVALENT_PRIVE'], $views);
    }

    /**
     * C6.1 : fiche avec `detail=full` → hypothèses structurelles (gel du point, taux
     * primes moyen, population stationnaire) + hypothèses de vue listées ;
     * `parametersVersion` affichée.
     */
    public function testFullDetailListsAllAssumptionsAndVersion(): void
    {
        $id = $this->seedScenario('ver-c6', []);

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?views=BUDGETAIRE,EQUIVALENT_PRIVE&horizons=year1&detail=full',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Version du jeu de paramètres affichée.
        self::assertSame('ver-c6', trim($crawler->filter('[data-testid="parameters-version"]')->text()));

        // Hypothèses structurelles nommées : gel du point, taux de primes moyen, population stationnaire.
        $structural = implode(' | ', $crawler->filter('[data-testid="structural-assumption"]')->each(
            static fn ($node): string => trim($node->text()),
        ));
        self::assertStringContainsString("point d'indice", $structural);
        self::assertStringContainsString('primes moyen', $structural);
        self::assertStringContainsString('Population stationnaire', $structural);

        // Hypothèses de vue listées pour chaque vue demandée.
        $viewAssumptionViews = $crawler->filter('[data-testid="view-assumptions"]')->each(
            static fn ($node): string => (string) $node->attr('data-view'),
        );
        self::assertSame(['BUDGETAIRE', 'EQUIVALENT_PRIVE'], $viewAssumptionViews);

        // La vue EQUIVALENT_PRIVE porte au moins une hypothèse nommée.
        $private = $crawler->filter('[data-testid="view-assumptions"][data-view="EQUIVALENT_PRIVE"]');
        self::assertGreaterThanOrEqual(1, $private->filter('[data-testid="view-assumption"]')->count());
    }

    /**
     * @param list<array{rank: int, increasedIndex: int, durationMonths: int|null}> $extraEchelons
     */
    private function seedScenario(string $version, array $extraEchelons): int
    {
        $set = $this->persistParameterSet($this->em, $version);
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

        $scenario = new Scenario();
        $scenario->title = 'Transparence';
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

    private function calculator(): ScenarioResultCalculator
    {
        $calculator = static::getContainer()->get(ScenarioResultCalculator::class);
        self::assertInstanceOf(ScenarioResultCalculator::class, $calculator);

        return $calculator;
    }
}
