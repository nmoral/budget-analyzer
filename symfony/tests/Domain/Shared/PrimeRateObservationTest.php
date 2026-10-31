<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\CostInput;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * C3 — taux de primes p renvoyé par le port avec son drapeau fallback et l'entrée
 * datée tracée (transparence, #20/#65).
 */
final class PrimeRateObservationTest extends TestCase
{
    public function testCarriesRateAndFallbackFlag(): void
    {
        $observation = new PrimeRateObservation(Rate::fromRatio('0.33'), true, $this->input());

        self::assertTrue($observation->rate()->equals(Rate::fromRatio('0.33')));
        self::assertTrue($observation->isFallback());
        self::assertFalse((new PrimeRateObservation(Rate::fromRatio('0.21'), false, $this->input()))->isFallback());
    }

    public function testCarriesTracedInput(): void
    {
        $observation = new PrimeRateObservation(Rate::fromRatio('0.21'), false, $this->input());

        $input = $observation->input();
        self::assertSame('taux_primes_PE', $input->key());
        self::assertSame('0.21', $input->value());
        self::assertSame('RATIO', $input->unit());
        self::assertSame('2026-01-01', $input->effectiveDate()->format('Y-m-d'));
    }

    private function input(): CostInput
    {
        return new CostInput('taux_primes_PE', '0.21', 'RATIO', new \DateTimeImmutable('2026-01-01'));
    }
}
