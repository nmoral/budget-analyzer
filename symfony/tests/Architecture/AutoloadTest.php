<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Exemple C4.1 : après `composer dump-autoload`, les dossiers src/Domain/ et
 * src/Infrastructure/ sont versionnés (.gitkeep) et les préfixes PSR-4
 * App\Domain\ et App\Infrastructure\ sont mappés sans erreur.
 */
final class AutoloadTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../..';

    public function testDomainAndInfrastructurePsr4PrefixesAreDeclared(): void
    {
        $psr4 = $this->composerPsr4();

        self::assertArrayHasKey('App\\Domain\\', $psr4);
        self::assertArrayHasKey('App\\Infrastructure\\', $psr4);
    }

    public function testDomainAndInfrastructureDirectoriesAreVersioned(): void
    {
        self::assertFileExists(self::PROJECT_DIR . '/src/Domain/.gitkeep');
        self::assertFileExists(self::PROJECT_DIR . '/src/Infrastructure/.gitkeep');
    }

    public function testComposerAutoloaderRegistersThePrefixes(): void
    {
        $loader = require self::PROJECT_DIR . '/vendor/autoload.php';

        if (!$loader instanceof ClassLoader) {
            self::fail('vendor/autoload.php did not return a Composer ClassLoader.');
        }

        $prefixes = $loader->getPrefixesPsr4();

        self::assertArrayHasKey('App\\Domain\\', $prefixes);
        self::assertArrayHasKey('App\\Infrastructure\\', $prefixes);
    }

    /**
     * @return array<mixed>
     */
    private function composerPsr4(): array
    {
        $path = self::PROJECT_DIR . '/composer.json';
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            self::fail("Unable to read {$path}.");
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            self::fail("Invalid JSON in {$path}.");
        }

        $autoload = $data['autoload'] ?? null;
        if (!is_array($autoload)) {
            self::fail('composer.json has no "autoload" section.');
        }

        $psr4 = $autoload['psr-4'] ?? null;
        if (!is_array($psr4)) {
            self::fail('composer.json has no "autoload.psr-4" section.');
        }

        return $psr4;
    }
}
