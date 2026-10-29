<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Import;

use App\Infrastructure\Import\ManifestMismatchException;
use App\Infrastructure\Import\ManifestVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Vérification des empreintes SHA-256 des bruts (C4).
 */
final class ManifestVerifierTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /** C4.1 — tous les bruts correspondent à leur manifeste : aucune exception. */
    public function testMatchingRawsPassForEveryImportedFile(): void
    {
        $verifier = new ManifestVerifier();
        $files = [
            'point_indice', 'taux_cas_civil', 'taux_cas_militaire', 'taux_cnracl',
            'grille_pe', 'grille_isg', 'echelon_pe', 'echelon_isg', 'taux_primes',
        ];

        foreach ($files as $file) {
            $verifier->verify($this->dataDir(), $file);
        }

        $this->addToAssertionCount(1);
    }

    /** C4.2 — `sha256(data/sources/taux_cnracl.json)` ≠ manifeste : refus. */
    public function testDivergentHashIsRejected(): void
    {
        $dir = $this->copyData();
        file_put_contents($dir . '/sources/taux_cnracl.json', '{"altered": true}');

        $verifier = new ManifestVerifier();

        $this->expectException(ManifestMismatchException::class);
        $this->expectExceptionMessage('empreinte');

        $verifier->verify($dir, 'taux_cnracl');
    }

    private function copyData(): string
    {
        $dst = sys_get_temp_dir() . '/import-manifest-' . uniqid('', true);
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
