<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Import;

use App\Tests\Infrastructure\Persistence\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commande `app:import <commit>` : import atomique de bout en bout (C1, C2).
 */
final class ImportCommandTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /** C1.1 — import nominal du dépôt réel : exit 0, ParameterSet + valeurs datées. */
    public function testNominalImportCreatesVersionedParameterSet(): void
    {
        $before = new \DateTimeImmutable();
        $tester = $this->runImport('9f3a1c2', $this->dataDir());
        $after = new \DateTimeImmutable();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT version_id, imported_at FROM parameter_set');
        self::assertIsArray($row);
        self::assertSame('9f3a1c2', $row['version_id']);

        $importedAtValue = $row['imported_at'];
        if (!is_string($importedAtValue)) {
            self::fail('imported_at devrait être une chaîne horodatée');
        }
        $importedAt = new \DateTimeImmutable($importedAtValue);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $importedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $importedAt->getTimestamp());

        self::assertSame(1, $this->rowCount('parameter_set'));
        self::assertSame(7, $this->rowCount('dated_parameter'));
        // Les 8 slugs de l'ensemble des CSV chargés sont résolus et persistés (J2).
        self::assertSame(8, $this->rowCount('source'));
    }

    /** C2.1 — schéma invalide (grille sans `version`) : exit ≠ 0, rollback total. */
    public function testInvalidSchemaRollsBackEverything(): void
    {
        $dir = $this->copyData();
        $this->dropColumnFromCsv($dir . '/data/grille_pe.csv', 'version');

        $tester = $this->runImport('deadbeef', $dir);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('colonne requise `version` manquante', $tester->getDisplay());

        self::assertSame(0, $this->rowCount('parameter_set'));
        self::assertSame(0, $this->rowCount('dated_parameter'));
        self::assertSame(0, $this->rowCount('source'));
    }

    /** C2.2 — empreinte divergente (brut point_indice altéré) : exit ≠ 0, rollback total. */
    public function testHashDivergenceRollsBackEverything(): void
    {
        $dir = $this->copyData();
        file_put_contents($dir . '/sources/point_indice.json', '{"altered": true}');

        $tester = $this->runImport('cafe1234', $dir);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('empreinte', $tester->getDisplay());

        self::assertSame(0, $this->rowCount('parameter_set'));
        self::assertSame(0, $this->rowCount('dated_parameter'));
        self::assertSame(0, $this->rowCount('source'));
    }

    /** C5.1 — peuplement réel : comptes exacts et FK ParameterSet partout. */
    public function testRealImportPopulatesEveryEntityWithExpectedCounts(): void
    {
        $tester = $this->runImport('9f3a1c2', $this->dataDir());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        self::assertSame(7, $this->rowCount('dated_parameter'));
        self::assertSame(2, $this->rowCount('grille'));
        self::assertSame(22, $this->rowCount('echelon'));
        self::assertSame(2, $this->rowCount('prime_rate'));
        self::assertSame(8, $this->rowCount('source'));
        self::assertSame(2, $this->rowCount('corps'));

        $setId = $this->fetchInt("SELECT id FROM parameter_set WHERE version_id = '9f3a1c2'");
        self::assertGreaterThan(0, $setId);

        // Toutes les valeurs datées (scopées) portent la FK du ParameterSet du commit.
        foreach (['dated_parameter', 'grille', 'prime_rate'] as $table) {
            self::assertSame(
                $this->rowCount($table),
                $this->rowCountWhere($table, 'parameter_set_id = ' . $setId),
                sprintf('toutes les lignes de %s doivent référencer le ParameterSet', $table),
            );
        }
    }

    /** C5.2 — résolution Echelon → Grille par la clé `{body_code}_{grade}_{version}`. */
    public function testEchelonsResolveToTheirGrilleByCompositeKey(): void
    {
        $tester = $this->runImport('9f3a1c2', $this->dataDir());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $peGrilleId = $this->fetchInt(
            'SELECT g.id FROM grille g JOIN corps c ON c.id = g.body_id '
            . "WHERE c.code = 'PE' AND g.grade = 'classe_normale' AND g.version = '2024'",
        );
        self::assertGreaterThan(0, $peGrilleId);

        // echelon_pe.csv (grille = PE_classe_normale_2024) → 11 échelons sur cette grille.
        self::assertSame(11, $this->rowCountWhere('echelon', 'grille_id = ' . $peGrilleId));
        // ISG en apporte 11 autres → 22 au total.
        self::assertSame(22, $this->rowCount('echelon'));
    }

    /** C5.3 (source) — slug `source` absent du référentiel : refus atomique. */
    public function testUnknownSourceSlugIsRejected(): void
    {
        $dir = $this->copyData();
        $this->replaceInFile($dir . '/data/grille_pe.csv', 'decret-2017-789', 'slug-inexistant');

        $tester = $this->runImport('deadbeef', $dir);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('source inconnue `slug-inexistant`', $tester->getDisplay());
        self::assertSame(0, $this->rowCount('parameter_set'));
        self::assertSame(0, $this->rowCount('grille'));
        self::assertSame(0, $this->rowCount('source'));
    }

    /** C5.3 (corps) — `body_code` absent du référentiel : refus atomique. */
    public function testUnknownCorpsCodeIsRejected(): void
    {
        $dir = $this->copyData();
        $this->replaceInFile($dir . '/data/grille_pe.csv', 'PE,classe_normale', 'XX,classe_normale');

        $tester = $this->runImport('deadbeef', $dir);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('corps inconnu `XX`', $tester->getDisplay());
        self::assertSame(0, $this->rowCount('parameter_set'));
        self::assertSame(0, $this->rowCount('corps'));
        self::assertSame(0, $this->rowCount('grille'));
    }

    /** C5.4 — Source persiste tous ses champs curatés. */
    public function testSourcePersistsAllCuratedFields(): void
    {
        $tester = $this->runImport('9f3a1c2', $this->dataDir());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT code, label, publisher, url, retrieved_at, file_hash FROM source WHERE code = 'decret-2023-519'",
        );
        self::assertIsArray($row);
        self::assertSame('decret-2023-519', $row['code']);
        self::assertSame('Décret n° 2023-519 du 28 juin 2023', $row['label']);
        self::assertSame('Légifrance/JORF', $row['publisher']);
        self::assertSame('https://www.legifrance.gouv.fr/eli/decret/2023/6/28/2023-519/jo/texte', $row['url']);
        $retrievedAt = $row['retrieved_at'];
        if (!is_string($retrievedAt)) {
            self::fail('retrieved_at devrait être une chaîne');
        }
        self::assertStringStartsWith('2026-10-19', $retrievedAt);
        self::assertSame('03eff22fb00d9d1326b35fae7fe60594ff400542854c81d1014deab87b70f067', $row['file_hash']);
    }

    /** C5.5 — Corps persiste tous ses champs curatés. */
    public function testCorpsPersistsAllCuratedFields(): void
    {
        $tester = $this->runImport('9f3a1c2', $this->dataDir());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $conn = $this->em->getConnection();

        $pe = $conn->fetchAssociative("SELECT label, versant, category FROM corps WHERE code = 'PE'");
        self::assertIsArray($pe);
        self::assertSame('professeur des écoles', $pe['label']);
        self::assertSame('FPE', $pe['versant']);
        self::assertSame('A', $pe['category']);

        $isg = $conn->fetchAssociative("SELECT label, versant, category FROM corps WHERE code = 'ISG'");
        self::assertIsArray($isg);
        self::assertSame('infirmier en soins généraux', $isg['label']);
        self::assertSame('FPH', $isg['versant']);
        self::assertSame('A', $isg['category']);
    }

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }

    private function replaceInFile(string $path, string $search, string $replace): void
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $updated = str_replace($search, $replace, $contents);
        self::assertNotSame($contents, $updated);
        file_put_contents($path, $updated);
    }

    private function runImport(string $commit, string $dataDir): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:import');
        $tester = new CommandTester($command);
        $tester->execute(['commit' => $commit, '--data-dir' => $dataDir]);

        return $tester;
    }

    private function rowCount(string $table): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM ' . $table);
    }

    private function rowCountWhere(string $table, string $where): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
    }

    private function fetchInt(string $sql): int
    {
        $result = $this->em->getConnection()->fetchOne($sql);
        if (is_int($result)) {
            return $result;
        }
        if (is_string($result) && ctype_digit($result)) {
            return (int) $result;
        }

        self::fail('résultat SQL non entier : ' . $sql);
    }

    private function dropColumnFromCsv(string $path, string $column): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $header = str_getcsv((string) $lines[0], ',', '"', '');
        $index = array_search($column, $header, true);
        self::assertIsInt($index);

        $rewritten = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            unset($cells[$index]);
            $rewritten[] = implode(',', array_values($cells));
        }
        file_put_contents($path, implode("\n", $rewritten) . "\n");
    }

    private function copyData(): string
    {
        $dst = sys_get_temp_dir() . '/import-command-' . uniqid('', true);
        $this->tempDirs[] = $dst;
        $this->recursiveCopy($this->dataDir(), $dst);

        return $dst;
    }

    private function recursiveCopy(string $src, string $dst): void
    {
        mkdir($dst, 0o777, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, 0o777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
