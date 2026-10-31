<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le pool de cache des résultats (#19) est configuré et disponible dans le
 * conteneur (câblage effectif du cache en J3).
 */
final class ScenarioResultCachePoolTest extends KernelTestCase
{
    public function testResultCachePoolIsAvailable(): void
    {
        self::bootKernel();

        $pool = static::getContainer()->get('app.cache.scenario_result');

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }
}
