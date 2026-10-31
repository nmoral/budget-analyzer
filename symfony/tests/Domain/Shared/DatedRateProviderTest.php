<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;

/**
 * Le port de domaine `DatedRateProvider` renvoie un `DatedRate` (taux + entrée
 * tracée) pour une clé à une date, et lève `MissingDatedRateException` lorsque la
 * date n'est pas couverte (amorce de C3/C5, exercée ici via une implémentation
 * factice PURE).
 */
final class DatedRateProviderTest extends TestCase
{
    public function testFakeProviderReturnsRateForKnownKey(): void
    {
        $provider = $this->fakeProvider();

        $rate = $provider->rateAt('taux_cas_civil', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('82.28', $rate->rate()->percentage());
    }

    public function testFakeProviderThrowsWhenDateNotCovered(): void
    {
        $provider = $this->fakeProvider();

        $this->expectException(MissingDatedRateException::class);

        $provider->rateAt('taux_cas_civil', new \DateTimeImmutable('2024-06-01'));
    }

    private function fakeProvider(): DatedRateProvider
    {
        return new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                if ($date < new \DateTimeImmutable('2026-01-01')) {
                    throw MissingDatedRateException::forKeyAndDate($key, $date);
                }

                return Trace::datedRate(Rate::fromPercentage('82.28'));
            }
        };
    }
}
