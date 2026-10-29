<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Exemple C4.2 : src/Domain/ reste du PHP pur, sans aucune référence à
 * Doctrine ni à une Entity (frontière domaine / infrastructure).
 */
final class DomainBoundaryTest extends TestCase
{
    private const string DOMAIN_DIR = __DIR__ . '/../../src/Domain';

    public function testDomainContainsNoDoctrineNorEntityReference(): void
    {
        self::assertDirectoryExists(self::DOMAIN_DIR);

        $violations = [];
        foreach ($this->domainFiles() as $file) {
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match('/Doctrine|Entity/i', $contents) === 1) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $violations,
            'src/Domain/ must stay free of any Doctrine/Entity reference.',
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function domainFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                self::DOMAIN_DIR,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }
}
