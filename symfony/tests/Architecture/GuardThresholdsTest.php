<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Exemple C7.1 : aucun garde-fou PHP existant n'est baissé par #12. Ce test
 * verrouille les seuils dans les fichiers de config versionnés : toute tentative
 * d'abaisser PHPStan, les `failOn*` de PHPUnit ou le mode risky de PHP-CS-Fixer
 * le fait virer au rouge.
 */
final class GuardThresholdsTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../..';

    public function testPhpstanStaysAtLevelMax(): void
    {
        self::assertStringContainsString(
            'level: max',
            $this->read('/phpstan.dist.neon'),
            'PHPStan must stay at level: max.',
        );
    }

    public function testPhpunitKeepsFailOnGuards(): void
    {
        $config = $this->read('/phpunit.dist.xml');

        self::assertStringContainsString('failOnWarning="true"', $config);
        self::assertStringContainsString('failOnRisky="true"', $config);
        self::assertStringContainsString('failOnDeprecation="true"', $config);
    }

    public function testPhpCsFixerKeepsStrictRules(): void
    {
        $config = $this->read('/.php-cs-fixer.dist.php');

        self::assertStringContainsString('setRiskyAllowed(true)', $config);
        self::assertStringContainsString("'declare_strict_types' => true", $config);
        self::assertStringContainsString("'strict_comparison' => true", $config);
    }

    private function read(string $relativePath): string
    {
        $path = self::PROJECT_DIR . $relativePath;
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            self::fail("Unable to read {$path}.");
        }

        return $contents;
    }
}
