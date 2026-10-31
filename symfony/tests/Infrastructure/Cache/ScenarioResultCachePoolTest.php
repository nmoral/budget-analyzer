<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le pool de cache des résultats (#19) est configuré et disponible dans le
 * conteneur (câblage effectif du cache en J3) et son stockage est indépendant de
 * l'environnement résolu par le process (A1/J4).
 */
final class ScenarioResultCachePoolTest extends KernelTestCase
{
    public function testResultCachePoolIsAvailable(): void
    {
        self::bootKernel();

        $pool = static::getContainer()->get('app.cache.scenario_result');

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    /**
     * A1 (C6.2) : le pool de résultats doit écrire dans un répertoire FIXE, hors
     * `%kernel.cache_dir%` (donc indépendant de l'environnement). Sans cela, le
     * serveur web (prod) et la console `app:import` (dev) écrivent sous
     * `var/cache/<env>/pools`, répertoires distincts : la purge à l'import rate le
     * pool réellement lu par l'API. On prouve ici que l'entrée écrite se matérialise
     * sous le répertoire fixe `var/cache/scenario_result` et non sous le cache d'env.
     */
    public function testResultCachePoolStorageIsEnvironmentIndependent(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $pool = $container->get('app.cache.scenario_result');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $cacheDir = $container->getParameter('kernel.cache_dir');
        self::assertIsString($cacheDir);
        $fixedDir = $projectDir . '/var/cache/scenario_result';

        // Le répertoire fixe ne doit pas être un sous-répertoire du cache d'environnement.
        self::assertFalse(
            str_starts_with($fixedDir, $cacheDir),
            'le répertoire fixe ne doit pas dépendre du cache d\'environnement',
        );

        $pool->clear();
        $item = $pool->getItem('env_independence_probe');
        $item->set('x');
        $pool->save($item);

        try {
            self::assertDirectoryExists($fixedDir, 'le pool doit stocker dans le répertoire fixe');
            self::assertNotSame([], $this->filesUnder($fixedDir), 'l\'entrée doit se matérialiser sous le répertoire fixe');
        } finally {
            $pool->clear();
        }
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
