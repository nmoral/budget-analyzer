<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\CostInput;
use PHPUnit\Framework\TestCase;

/**
 * Value object PUR `CostInput` (#65) : une entrée datée utilisée par le calcul
 * (clé, valeur, unité, date d'effet), avec égalité par valeur.
 */
final class CostInputTest extends TestCase
{
    public function testExposesItsFourComponents(): void
    {
        $input = new CostInput('taux_cas_civil', '82.28', 'PCT_TIB', new \DateTimeImmutable('2026-01-01'));

        self::assertSame('taux_cas_civil', $input->key());
        self::assertSame('82.28', $input->value());
        self::assertSame('PCT_TIB', $input->unit());
        self::assertSame('2026-01-01', $input->effectiveDate()->format('Y-m-d'));
    }

    public function testEqualsWhenAllComponentsMatch(): void
    {
        self::assertTrue($this->base()->equals($this->base()));
    }

    public function testDiffersWhenKeyDiffers(): void
    {
        $other = new CostInput('autre', '82.28', 'PCT_TIB', new \DateTimeImmutable('2026-01-01'));
        self::assertFalse($this->base()->equals($other));
    }

    public function testDiffersWhenValueDiffers(): void
    {
        $other = new CostInput('taux_cas_civil', '99.99', 'PCT_TIB', new \DateTimeImmutable('2026-01-01'));
        self::assertFalse($this->base()->equals($other));
    }

    public function testDiffersWhenUnitDiffers(): void
    {
        $other = new CostInput('taux_cas_civil', '82.28', 'RATIO', new \DateTimeImmutable('2026-01-01'));
        self::assertFalse($this->base()->equals($other));
    }

    public function testDiffersWhenEffectiveDateDiffers(): void
    {
        $other = new CostInput('taux_cas_civil', '82.28', 'PCT_TIB', new \DateTimeImmutable('2025-01-01'));
        self::assertFalse($this->base()->equals($other));
    }

    private function base(): CostInput
    {
        return new CostInput('taux_cas_civil', '82.28', 'PCT_TIB', new \DateTimeImmutable('2026-01-01'));
    }
}
