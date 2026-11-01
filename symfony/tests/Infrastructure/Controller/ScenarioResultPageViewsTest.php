<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Infrastructure\Cost\ScenarioResultCalculator;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiche de résultat web (#20, J2) : montants par vue et horizon + financeur.
 *
 * C2 : vues demandées (défaut BUDGETAIRE) côte à côte, chacune nommée ; année 1
 *      (3 fenêtres, schoolYear masquée si absente), permanent, cohorte (série +
 *      total), agrégat par vue/horizon ; montants repris tels quels du service.
 * C7 : financeur par ligne via Profile.funder ; aucune part de budget affichée.
 */
final class ScenarioResultPageViewsTest extends WebTestCase
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
     * C2.1 : `?views=BUDGETAIRE,EQUIVALENT_PRIVE&horizons=year1,permanent,cohorte`
     * → 2 blocs de vue nommés côte à côte ; année1 (3 fenêtres), permanent, cohorte
     * (série + total) présents ; agrégat affiché ; vues affichées indiquées. Les
     * montants sont repris tels quels du service.
     */
    public function testMultipleViewsAndHorizonsRenderedSideBySide(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-c2');
        $profile = $this->persistComputableProfile($this->em, $set);
        $this->em->flush();
        $id = $this->seedScenario('Scénario multi-vues', $profile);

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?views=BUDGETAIRE,EQUIVALENT_PRIVE&horizons=year1,permanent,cohorte',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Deux blocs de vue d'agrégat, nommés, dans l'ordre de l'URL (côte à côte).
        $aggregateViews = $crawler->filter('[data-testid="aggregate-view"]')->each(
            static fn ($node): string => (string) $node->attr('data-view'),
        );
        self::assertSame(['BUDGETAIRE', 'EQUIVALENT_PRIVE'], $aggregateViews);
        $names = $crawler->filter('[data-testid="aggregate-view"] [data-testid="view-name"]')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['BUDGETAIRE', 'EQUIVALENT_PRIVE'], $names);

        // Vue(s) affichée(s) toujours indiquée(s).
        $displayed = $crawler->filter('[data-testid="displayed-views"]')->text();
        self::assertStringContainsString('BUDGETAIRE', $displayed);
        self::assertStringContainsString('EQUIVALENT_PRIVE', $displayed);

        // Année 1 : les 3 fenêtres (calendrier scolaire → schoolYear présente).
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-fullYear"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-year1"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-schoolYear"]')->count());

        // Régime permanent et cohorte (série + total).
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-permanent"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="horizon-cohorte"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="cohorte-year"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="cohorte-total"]')->count());

        // Montants repris tels quels : le total agrégé BUDGETAIRE/fullYear du service
        // apparaît verbatim dans le HTML (aucun reformatage côté front).
        $result = $this->calculator()->compute($this->em->find(Scenario::class, $id) ?? throw new \LogicException('scénario introuvable'), ['year1', 'permanent', 'cohorte'], ['BUDGETAIRE', 'EQUIVALENT_PRIVE']);
        /** @var array{aggregate: array<string, array{year1: array{fullYear: array<string, string>}}>} $result */
        $expectedTotal = $result['aggregate']['BUDGETAIRE']['year1']['fullYear']['total'];
        self::assertStringContainsString($expectedTotal, (string) $this->client->getResponse()->getContent());
    }

    /**
     * C2.2 : profil au calendrier civil (sans fenêtre scolaire), `horizons=year1`
     * → `schoolYear` non affichée ; `fullYear` et `year1` affichées.
     */
    public function testSchoolYearWindowHiddenWhenAbsent(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-c22');
        $profile = $this->persistComputableProfile($this->em, $set, referenceCalendar: 'civil');
        $this->em->flush();
        $id = $this->seedScenario('Scénario civil', $profile);

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?horizons=year1&views=BUDGETAIRE');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-fullYear"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-year1"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="window-schoolYear"]')->count());
    }

    /**
     * C7.1 : scénario dont la ligne pointe un profil FPH → le financeur (nom) est
     * affiché pour la ligne ; aucune « part de budget de l'État » nulle part.
     */
    public function testFunderDisplayedPerLineAndNoBudgetShare(): void
    {
        $set = $this->persistParameterSet($this->em, 'ver-c7');
        $profile = $this->persistComputableProfile(
            $this->em,
            $set,
            versant: 'FPH',
            funderCode: 'FPH_CHR',
            funderLabel: 'Centre hospitalier régional',
        );
        $this->em->flush();
        $id = $this->seedScenario('Scénario FPH', $profile);

        $crawler = $this->client->request('GET', '/scenarios/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $funders = $crawler->filter('[data-testid="line-funder"]')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['Centre hospitalier régional'], $funders);

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('part du budget', $content);
        self::assertStringNotContainsStringIgnoringCase('part de budget', $content);
        self::assertStringNotContainsStringIgnoringCase("budget de l'état", $content);
    }

    private function seedScenario(string $title, Profile $profile): int
    {
        $scenario = new Scenario();
        $scenario->title = $title;
        $scenario->provenance = 'simulation';
        $scenario->parameterSet = $profile->entryEchelon->grille->parameterSet;
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

    private function calculator(): ScenarioResultCalculator
    {
        $calculator = static::getContainer()->get(ScenarioResultCalculator::class);
        self::assertInstanceOf(ScenarioResultCalculator::class, $calculator);

        return $calculator;
    }
}
