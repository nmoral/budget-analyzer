<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Exemple C2.2 : la sélection d'une règle se fait par service locator indexé par
 * code, jamais par un `match`/`if` sur un code métier en dur dans le moteur.
 *
 * Preuve mécanique : les fichiers *Resolver.php de src/Domain ne contiennent ni
 * expression `match`, ni littéral ressemblant à un code métier (identifiant en
 * capitales). La sélection passe donc nécessairement par le locator injecté.
 */
final class RuleResolverSelectionTest extends TestCase
{
    private const string RULE_DIR = __DIR__ . '/../../src/Domain/Rule';

    public function testResolversUseNoMatchExpression(): void
    {
        foreach ($this->resolverFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            self::assertDoesNotMatchRegularExpression(
                '/\bmatch\s*\(/',
                $contents,
                $file->getFilename() . ' must not select a rule with a match expression.',
            );
        }
    }

    public function testResolversHardcodeNoBusinessCode(): void
    {
        foreach ($this->resolverFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            self::assertDoesNotMatchRegularExpression(
                '/[\'"][A-Z][A-Z0-9_]{2,}[\'"]/',
                $contents,
                $file->getFilename() . ' must not branch on a hardcoded business code.',
            );
        }
    }

    public function testThereIsAtLeastOneResolver(): void
    {
        self::assertNotEmpty(
            iterator_to_array($this->resolverFiles()),
            'Expected at least one *Resolver.php under src/Domain/Rule.',
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function resolverFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                self::RULE_DIR,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), 'Resolver.php')) {
                yield $file;
            }
        }
    }
}
