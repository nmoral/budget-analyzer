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
 * Fiche de résultat web (#20, J3) : dépliage year1 jusqu'à la source (`detail=full`).
 *
 * C3 : chaque nombre des fenêtres year1 se déplie (Stimulus + contenu présent dans le
 *      HTML, lisible sans JS) sur formule + entrées datées (clé/valeur/unité/date
 *      d'effet) + prorata + sources en clair (éditeur — titre, édition, localisation,
 *      puis lien), jamais un code ni un lien nu. Le détail ne concerne que year1.
 */
final class ScenarioResultPageDetailTest extends WebTestCase
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
     * C3.1 : déplier le terme `treatmentAndPrimes` de la fenêtre year1 →
     * formule + entrées datées (clé/valeur/unité/date d'effet) + prorata + sources
     * en clair présents dans le HTML, avec le câblage Stimulus (bouton repliable).
     */
    public function testYear1TermUnfoldsToFormulaInputsProrataAndSources(): void
    {
        $id = $this->seedScenario('ver-c3');

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1&detail=full',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $base = '[data-testid="scenario-line"] [data-testid="window-year1"] [data-term="treatmentAndPrimes"]';

        // Câblage Stimulus : contrôleur de dépliage + bouton (contenu néanmoins présent).
        self::assertSame(1, $crawler->filter($base . ' [data-controller="disclosure"]')->count());
        self::assertSame(1, $crawler->filter($base . ' [data-testid="term-toggle"][data-action="disclosure#toggle"]')->count());

        $detail = $crawler->filter($base . ' [data-testid="term-detail"]');
        self::assertSame(1, $detail->count());

        // Formule présente et non vide.
        self::assertNotSame('', trim($detail->filter('[data-testid="detail-formula"]')->text()));

        // Entrée datée : clé, valeur, unité, date d'effet, toutes renseignées.
        $input = $detail->filter('[data-testid="detail-input"]')->first();
        self::assertGreaterThanOrEqual(1, $detail->filter('[data-testid="detail-input"]')->count());
        foreach (['key', 'value', 'unit', 'effectiveDate'] as $field) {
            self::assertNotSame('', trim($input->filter('[data-field="' . $field . '"]')->text()), $field);
        }

        // Prorata présent.
        self::assertNotSame('', trim($detail->filter('[data-testid="detail-prorata"]')->text()));

        // Sources en clair : au moins une, avec citation ET lien.
        self::assertGreaterThanOrEqual(1, $detail->filter('[data-testid="source"]')->count());
        self::assertGreaterThanOrEqual(1, $detail->filter('[data-testid="source-citation"]')->count());
        self::assertGreaterThanOrEqual(1, $detail->filter('[data-testid="source-link"]')->count());
    }

    /**
     * C3.2 : une source du dépliage s'affiche « éditeur — titre, édition, localisation »
     * puis le lien ; jamais le code brut seul, jamais une URL nue seule.
     */
    public function testSourceShownInClearNeverCodeNorBareUrl(): void
    {
        $id = $this->seedScenario('ver-c32');

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1&detail=full',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $expected = $this->firstTreatmentSource($id);

        $base = '[data-testid="scenario-line"] [data-testid="window-year1"] [data-term="treatmentAndPrimes"]';
        $source = $crawler->filter($base . ' [data-testid="source"]')->first();

        $citation = trim($source->filter('[data-testid="source-citation"]')->text());
        self::assertSame(
            sprintf('%s — %s, %s, %s', $expected['publisher'], $expected['label'], $expected['edition'], $expected['locator']),
            $citation,
        );

        // Le lien pointe l'URL mais n'est jamais une URL nue : son texte est lisible.
        $link = $source->filter('[data-testid="source-link"]');
        self::assertSame($expected['url'], $link->attr('href'));
        self::assertNotSame($expected['url'], trim($link->text()));
        self::assertSame($expected['label'], trim($link->text()));

        // Jamais le code brut de la source dans la page.
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($expected['code'], $content);
    }

    /**
     * Le détail ne concerne que les fenêtres year1 : ni l'agrégat, ni permanent, ni
     * cohorte ne portent de bloc détail, même avec `detail=full`.
     */
    public function testDetailOnlyOnYear1PerLineWindows(): void
    {
        $id = $this->seedScenario('ver-c3-scope');

        $crawler = $this->client->request(
            'GET',
            '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1,permanent,cohorte&detail=full',
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame(0, $crawler->filter('[data-testid="aggregate-section"] [data-testid="term-detail"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="horizon-permanent"] [data-testid="term-detail"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="horizon-cohorte"] [data-testid="term-detail"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-year1"] [data-testid="term-detail"]')->count());
    }

    /** Sans `detail=full`, aucun bloc détail n'est rendu (réponse légère inchangée). */
    public function testNoDetailWithoutFlag(): void
    {
        $id = $this->seedScenario('ver-c3-nodetail');

        $crawler = $this->client->request('GET', '/scenarios/' . $id . '?views=BUDGETAIRE&horizons=year1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame(0, $crawler->filter('[data-testid="term-detail"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="window-year1"]')->count());
    }

    /**
     * @return array{code: string, publisher: string, label: string, edition: string, locator: string, url: string}
     */
    private function firstTreatmentSource(int $id): array
    {
        $scenario = $this->em->find(Scenario::class, $id) ?? throw new \LogicException('scénario introuvable');
        $result = $this->calculator()->compute($scenario, ['year1'], ['BUDGETAIRE'], true);
        /** @var array{lines: list<array{results: array<string, array{year1: array{year1: array{detail: array<string, array{sources: list<array<string, string>>}>}}}>}>} $result */
        $sources = $result['lines'][0]['results']['BUDGETAIRE']['year1']['year1']['detail']['treatmentAndPrimes']['sources'];
        self::assertNotEmpty($sources);
        $source = $sources[0];

        return [
            'code' => $source['code'],
            'publisher' => $source['publisher'],
            'label' => $source['label'],
            'edition' => $source['edition'],
            'locator' => $source['locator'],
            'url' => $source['url'],
        ];
    }

    private function seedScenario(string $version): int
    {
        $set = $this->persistParameterSet($this->em, $version);
        $profile = $this->persistComputableProfile($this->em, $set);
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
