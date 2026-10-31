<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Shared\Amount;
use App\Domain\Shared\CostInput;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\Rate;

/**
 * Fabriques de test pour les value objects de trace (#65) : enveloppent un
 * `Rate`/`Amount` dans son porteur tracé avec une entrée par défaut, afin que les
 * doubles de ports qui ne s'intéressent qu'au scalaire restent concis. Les tests
 * de trace fournissent une `CostInput` explicite.
 */
final class Trace
{
    public static function input(
        string $key = 'key',
        string $value = '0',
        string $unit = 'UNIT',
        string $effectiveDate = '2026-01-01',
    ): CostInput {
        return new CostInput($key, $value, $unit, new \DateTimeImmutable($effectiveDate));
    }

    public static function datedRate(Rate $rate, ?CostInput $input = null): DatedRate
    {
        return new DatedRate($rate, $input ?? self::input());
    }

    public static function datedAmount(Amount $amount, ?CostInput $input = null): DatedAmount
    {
        return new DatedAmount($amount, $input ?? self::input());
    }

    public static function primeObservation(Rate $rate, bool $fallback, ?CostInput $input = null): PrimeRateObservation
    {
        return new PrimeRateObservation($rate, $fallback, $input ?? self::input());
    }
}
