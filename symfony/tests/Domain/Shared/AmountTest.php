<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\Amount;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Value object de domaine PUR `Amount` : montant décimal-safe à l'échelle 6
 * (aucun float), produit par un taux ou un indice entier, somme exacte,
 * égalité par valeur (C1).
 */
final class AmountTest extends TestCase
{
    public function testMultipliedByRate(): void
    {
        // C1.1 : 23333.993 × 82.28 % → 19199.209440.
        $product = Amount::fromString('23333.993')->multipliedByRate(Rate::fromPercentage('82.28'));

        self::assertSame('19199.209440', $product->toString());
    }

    public function testProductIsRoundedHalfUpToSixDecimals(): void
    {
        // C1.2 : 31034.210690 × 12 % = 3724.1052828 exact → 3724.105283 (half-up).
        $product = Amount::fromString('31034.210690')->multipliedByRate(Rate::fromPercentage('12'));

        self::assertSame('3724.105283', $product->toString());
    }

    public function testProductBelowHalfIsRoundedDown(): void
    {
        // 0.000001 × 40 % = 0.0000004 → 0.000000 ; × 50 % = 0.0000005 → 0.000001.
        self::assertSame('0.000000', Amount::fromString('0.000001')->multipliedByRate(Rate::fromPercentage('40'))->toString());
        self::assertSame('0.000001', Amount::fromString('0.000001')->multipliedByRate(Rate::fromPercentage('50'))->toString());
    }

    public function testNegativeProductIsRoundedHalfAwayFromZero(): void
    {
        self::assertSame('-0.000001', Amount::fromString('-0.000001')->multipliedByRate(Rate::fromPercentage('50'))->toString());
    }

    public function testMultipliedByIndex(): void
    {
        // Prépare C2.1 : point 59.0734 × IM 395 → TIB 23333.993000.
        self::assertSame('23333.993000', Amount::fromString('59.0734')->multipliedByIndex(395)->toString());
    }

    public function testSumIsExact(): void
    {
        // Somme exacte, sans arrondi supplémentaire (C5.1 : 28234.131530 + 19199.209440).
        $sum = Amount::fromString('28234.131530')->plus(Amount::fromString('19199.209440'));

        self::assertSame('47433.340970', $sum->toString());
    }

    public function testEqualityByValue(): void
    {
        self::assertTrue(Amount::fromString('23333.993')->equals(Amount::fromString('23333.993000')));
        self::assertFalse(Amount::fromString('23333.993')->equals(Amount::fromString('23333.993001')));
    }

    public function testCanonicalRepresentation(): void
    {
        self::assertSame('12.000000', Amount::fromString('12')->toString());
        self::assertSame('-12.500000', Amount::fromString('-12.5')->toString());
        self::assertSame('0.000000', Amount::fromString('-0')->toString());
    }

    public function testExtraDecimalsAreRoundedHalfUpOnInput(): void
    {
        self::assertSame('1.000001', Amount::fromString('1.0000005')->toString());
        self::assertSame('1.000000', Amount::fromString('1.0000004')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'virgule décimale (C1.3)' => ['12,5'];
        yield 'vide' => [''];
        yield 'point final' => ['12.'];
        yield 'point initial' => ['.5'];
        yield 'signe plus' => ['+12'];
        yield 'exposant' => ['1e3'];
        yield 'espace' => [' 12'];
        yield 'suffixe' => ['12a'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $input): void
    {
        // C1.3 : Amount::fromString("12,5") → InvalidArgumentException.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $input));

        Amount::fromString($input);
    }
}
