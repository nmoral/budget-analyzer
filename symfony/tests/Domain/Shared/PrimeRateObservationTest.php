<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * C3 — taux de primes p renvoyé par le port avec son drapeau fallback
 * (transparence, #20).
 */
final class PrimeRateObservationTest extends TestCase
{
    public function testCarriesRateAndFallbackFlag(): void
    {
        $observation = new PrimeRateObservation(Rate::fromRatio('0.33'), true);

        self::assertTrue($observation->rate()->equals(Rate::fromRatio('0.33')));
        self::assertTrue($observation->isFallback());
        self::assertFalse((new PrimeRateObservation(Rate::fromRatio('0.21'), false))->isFallback());
    }
}
