<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #22 — C2 (end-to-end) : calibrage du coût en régime permanent du professeur
 * des écoles (PE_CN) contre le coût moyen chargé HCAS global du PAP programme 140.
 *
 * Moyen de vérification réelle : `app:import` des vraies données, puis
 * `POST /api/scenarios` (PE_CN, 2026-01-01, volume 1) et
 * `GET /api/scenarios/{id}/result?horizons=permanent&views=BUDGETAIRE`. La valeur
 * comparée est la vue BUDGETAIRE hors terme retraite (`treatmentAndPrimes +
 * otherContributions + rafp` = `total − retirement`) ; la référence est la ligne
 * (140, `enseignant_1er_degre`) d'année la plus récente de `data/data/couts_pap.csv`,
 * lu directement (non importé). Écart = (calculé − PAP) / PAP, seuil ±5 % inclusif.
 */
final class PapCalibrationApiTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private const string PROFILE = 'PE_CN';
    private const string RECRUITMENT = '2026-01-01';
    /** Correspondance PE_CN ↔ catégorie d'emploi du PAP, en dur (décision #22). */
    private const string PAP_PROGRAM = '140';
    private const string PAP_CATEGORY = 'enseignant_1er_degre';
    private const string THRESHOLD = '0.05';
    private const int SCALE = 10;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempDirs = [];

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
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->resultPool()->clear();
        $this->dropScenarioSchema($this->em);
        $this->em->clear();
        $this->em->close();

        parent::tearDown();
    }

    /**
     * #22 C2.1 — données réelles : PE_CN au 2026-01-01, volume 1, régime permanent, vue
     * BUDGETAIRE hors retraite, à ±5 % (inclusif) du PAP le plus récent (2025 : 46 139).
     */
    public function testPermanentCostOfPeCnMatchesLatestPapWithinThreshold(): void
    {
        $permanent = $this->computeRealPermanent();
        $computed = $this->comparedValue($permanent);

        // Valeur comparée = total − retraite (recomposition indépendante).
        self::assertSame(bcsub($permanent['total'], $permanent['retirement'], 6), $computed);

        $reference = $this->latestPapReference($this->dataDir());
        self::assertSame(2025, $reference['year']);
        self::assertSame('46139', $reference['cost']);
        self::assertSame('52', $reference['page']);

        $this->assertCalibrated($computed, $reference);

        echo \PHP_EOL . $this->report($computed, $reference) . \PHP_EOL;
    }

    /** #22 C2.2 — une ligne 2026 ajoutée pour (140, enseignant_1er_degre) devient la référence. */
    public function testReferenceIsTheMostRecentYear(): void
    {
        $dir = $this->dataDirWithPapRows(static function (array $rows): array {
            // Placée en tête : c'est l'année, pas la position, qui désigne la référence.
            array_unshift($rows, ['140', 'enseignant_1er_degre', '46500', '2026', 'PAP2026-ENSSCOL-P140', '50', 'https://example.org/pap2026.pdf']);

            return $rows;
        });

        $reference = $this->latestPapReference($dir);

        self::assertSame(2026, $reference['year']);
        self::assertSame('46500', $reference['cost']);
        self::assertSame('PAP2026-ENSSCOL-P140', $reference['document']);
        self::assertSame('50', $reference['page']);
    }

    /** #22 C2.3 — référence la plus récente à 30 000 : le contrôle échoue (calculé, PAP, écart %, seuil). */
    public function testCalibrationFailsBeyondThreshold(): void
    {
        $computed = $this->comparedValue($this->computeRealPermanent());
        $dir = $this->dataDirWithPapRows(
            /**
             * @param list<list<string>> $rows
             *
             * @return list<list<string>>
             */
            static function (array $rows): array {
                foreach ($rows as $index => $row) {
                    if ($row[3] === '2025') {
                        $rows[$index][2] = '30000';
                    }
                }

                return $rows;
            },
        );
        $reference = $this->latestPapReference($dir);
        self::assertSame('30000', $reference['cost']);

        $message = $this->failureMessageOf(fn () => $this->assertCalibrated($computed, $reference));

        $gapPercent = $this->formatPercent(bcdiv(bcsub($computed, '30000', self::SCALE), '30000', self::SCALE));
        self::assertStringContainsString('calculé ' . bcround($computed, 2) . ' €', $message);
        self::assertStringContainsString('PAP 2025 30000 €', $message);
        self::assertStringContainsString('écart ' . $gapPercent . ' %', $message);
        self::assertStringContainsString('seuil ±5 %', $message);
    }

    /** #22 C2.4 — aucune ligne (140, enseignant_1er_degre) : échec explicite, pas de skip. */
    public function testCalibrationFailsWhenReferenceIsMissing(): void
    {
        $dir = $this->dataDirWithPapRows(
            /**
             * @param list<list<string>> $rows
             *
             * @return list<list<string>>
             */
            static function (array $rows): array {
                $kept = [];
                foreach ($rows as $row) {
                    if ($row[0] !== '140' || $row[1] !== 'enseignant_1er_degre') {
                        $kept[] = $row;
                    }
                }

                return $kept;
            },
        );

        $message = $this->failureMessageOf(fn () => $this->latestPapReference($dir));

        self::assertStringContainsString('140', $message);
        self::assertStringContainsString('enseignant_1er_degre', $message);
    }

    /**
     * @param numeric-string                                                         $computed
     * @param array{year: int, cost: numeric-string, document: string, page: string} $reference
     */
    private function assertCalibrated(string $computed, array $reference): void
    {
        $gap = $this->gap($computed, $reference['cost']);
        $within = bccomp($gap, self::THRESHOLD, self::SCALE) <= 0
            && bccomp($gap, '-' . self::THRESHOLD, self::SCALE) >= 0;

        self::assertTrue($within, 'Calibrage hors seuil — ' . $this->report($computed, $reference));
    }

    /**
     * @param numeric-string                                                         $computed
     * @param array{year: int, cost: numeric-string, document: string, page: string} $reference
     */
    private function report(string $computed, array $reference): string
    {
        return \sprintf(
            '%s régime permanent (BUDGETAIRE hors retraite) : calculé %s € ; PAP %d %s € (%s p. %s) ; écart %s %% ; seuil ±5 %%',
            self::PROFILE,
            bcround($computed, 2),
            $reference['year'],
            $reference['cost'],
            $reference['document'],
            $reference['page'],
            $this->formatPercent($this->gap($computed, $reference['cost'])),
        );
    }

    /**
     * @param numeric-string $computed
     * @param numeric-string $pap
     *
     * @return numeric-string
     */
    private function gap(string $computed, string $pap): string
    {
        return bcdiv(bcsub($computed, $pap, self::SCALE), $pap, self::SCALE);
    }

    /** @param numeric-string $ratio */
    private function formatPercent(string $ratio): string
    {
        $percent = bcround(bcmul($ratio, '100', self::SCALE), 2);

        return (bccomp($percent, '0', 2) > 0 ? '+' : '') . $percent;
    }

    /**
     * Ligne (140, enseignant_1er_degre) d'année la plus récente de `<dir>/data/couts_pap.csv`.
     *
     * @return array{year: int, cost: numeric-string, document: string, page: string}
     */
    private function latestPapReference(string $dataDir): array
    {
        $reference = null;
        foreach ($this->papRows($dataDir) as $row) {
            [$program, $category, $cost, $year, $document, $page] = $row;
            if ($program !== self::PAP_PROGRAM || $category !== self::PAP_CATEGORY) {
                continue;
            }
            if ($reference === null || (int) $year > $reference['year']) {
                self::assertIsNumeric($cost);
                $reference = ['year' => (int) $year, 'cost' => $cost, 'document' => $document, 'page' => $page];
            }
        }

        if ($reference === null) {
            self::fail(\sprintf(
                'Aucune référence PAP (%s, %s) dans %s/data/couts_pap.csv : calibrage impossible.',
                self::PAP_PROGRAM,
                self::PAP_CATEGORY,
                $dataDir,
            ));
        }

        return $reference;
    }

    /**
     * Lignes de données de `couts_pap.csv`, colonnes dans l'ordre du schéma :
     * program, employment_category, average_loaded_cost, year, document, page, url.
     *
     * @return list<list<string>>
     */
    private function papRows(string $dataDir): array
    {
        $handle = fopen($dataDir . '/data/couts_pap.csv', 'r');
        self::assertNotFalse($handle);
        $header = fgetcsv($handle, escape: '');
        self::assertSame(['program', 'employment_category', 'average_loaded_cost', 'year', 'document', 'page', 'url'], $header);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Import des vraies données, scénario PE_CN (2026-01-01, volume 1), résultat permanent
     * BUDGETAIRE par agent.
     *
     * @return array{treatmentAndPrimes: numeric-string, retirement: numeric-string, otherContributions: numeric-string, rafp: numeric-string, total: numeric-string}
     */
    private function computeRealPermanent(): array
    {
        $this->import('calibrage-22', $this->dataDir());

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Calibrage #22',
                'lines' => [['profileRef' => self::PROFILE, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]],
            ]),
        );
        $created = $this->client->getResponse();
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        /** @var array{id: int} $scenario */
        $scenario = json_decode((string) $created->getContent(), true);

        $this->client->request('GET', '/api/scenarios/' . $scenario['id'] . '/result?horizons=permanent&views=BUDGETAIRE');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{lines: list<array{profileRef: string, results: array<string, array{permanent: array{treatmentAndPrimes: numeric-string, retirement: numeric-string, otherContributions: numeric-string, rafp: numeric-string, total: numeric-string}}>}>} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(self::PROFILE, $body['lines'][0]['profileRef']);

        return $body['lines'][0]['results']['BUDGETAIRE']['permanent'];
    }

    /**
     * @param array{treatmentAndPrimes: numeric-string, retirement: numeric-string, otherContributions: numeric-string, rafp: numeric-string, total: numeric-string} $permanent
     *
     * @return numeric-string
     */
    private function comparedValue(array $permanent): string
    {
        return bcadd(bcadd($permanent['treatmentAndPrimes'], $permanent['otherContributions'], 6), $permanent['rafp'], 6);
    }

    /**
     * Message de l'échec d'assertion levé par `$check` ; échoue si `$check` passe ou
     * se contente de marquer le test ignoré (un skip n'est pas un échec du contrôle).
     */
    private function failureMessageOf(callable $check): string
    {
        try {
            $check();
        } catch (AssertionFailedError $failure) {
            self::assertNotInstanceOf(SkippedTest::class, $failure, 'Le contrôle de calibrage doit échouer, pas être ignoré.');

            return $failure->getMessage();
        }

        self::fail('Le contrôle de calibrage aurait dû échouer.');
    }

    /**
     * Copie de `couts_pap.csv` (seul fichier lu par le contrôle) dont les lignes sont
     * transformées par `$transform`.
     *
     * @param callable(list<list<string>>): list<list<string>> $transform
     */
    private function dataDirWithPapRows(callable $transform): string
    {
        $dir = sys_get_temp_dir() . '/calibrage-22-' . uniqid('', true);
        $this->tempDirs[] = $dir;
        mkdir($dir . '/data', 0o777, true);

        $handle = fopen($dir . '/data/couts_pap.csv', 'w');
        self::assertNotFalse($handle);
        fputcsv($handle, ['program', 'employment_category', 'average_loaded_cost', 'year', 'document', 'page', 'url'], escape: '', eol: "\n");
        foreach ($transform($this->papRows($this->dataDir())) as $row) {
            fputcsv($handle, $row, escape: '', eol: "\n");
        }
        fclose($handle);

        return $dir;
    }

    private function import(string $commit, string $dataDir): void
    {
        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:import'));
        $tester->execute(['commit' => $commit, '--data-dir' => $dataDir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function resultPool(): CacheItemPoolInterface
    {
        $pool = static::getContainer()->get('app.cache.scenario_result');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
