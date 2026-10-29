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
        self::assertSame(4, $this->rowCount('source'));
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

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
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
        $result = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        if (is_int($result)) {
            return $result;
        }
        if (is_string($result) && ctype_digit($result)) {
            return (int) $result;
        }

        self::fail('COUNT(*) devrait être numérique');
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
