<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Value object de domaine PUR `Rate` : porteur décimal d'un pourcentage,
 * décimal-safe (aucun float en stockage interne), exposant pourcentage et
 * ratio, égalité par valeur (C2).
 */
final class RateTest extends TestCase
{
    public function testExposesPercentageAndRatioAndEqualsSameValue(): void
    {
        // C2.1 : Rate construit depuis « 82.28 ».
        $rate = Rate::fromPercentage('82.28');

        self::assertSame('82.28', $rate->percentage());
        self::assertSame('0.8228', $rate->ratio());
        self::assertTrue($rate->equals(Rate::fromPercentage('82.28')));
    }

    public function testDistinctValuesAreNotEqual(): void
    {
        // C2.1 : l'égalité est par valeur, pas par identité d'objet.
        self::assertFalse(Rate::fromPercentage('82.28')->equals(Rate::fromPercentage('37.65')));
    }

    public function testAcceptsTrailingZerosFromDecimalStorage(): void
    {
        // Les paramètres datés sont stockés en DECIMAL(14,6) : la valeur relue
        // porte des zéros de fin (« 82.280000 »). Elle doit donner un taux égal
        // à « 82.28 » et une représentation canonique sans zéros superflus.
        $rate = Rate::fromPercentage('82.280000');

        self::assertSame('82.28', $rate->percentage());
        self::assertSame('0.8228', $rate->ratio());
        self::assertTrue($rate->equals(Rate::fromPercentage('82.28')));
    }

    public function testZeroHasNoSign(): void
    {
        // Zéro n'est pas négatif : pas de signe parasite « -0 ».
        $zero = Rate::fromPercentage('0');

        self::assertSame('0', $zero->percentage());
        self::assertSame('0', $zero->ratio());
    }

    public function testCarriesSignForNegativeValues(): void
    {
        // Porteur neutre (sans validation de plage) : une valeur négative garde
        // son signe sur le pourcentage ET le ratio, et reste distincte de son opposé.
        $negative = Rate::fromPercentage('-82.28');

        self::assertSame('-82.28', $negative->percentage());
        self::assertSame('-0.8228', $negative->ratio());
        self::assertFalse($negative->equals(Rate::fromPercentage('82.28')));
    }

    public function testPreservesExactDecimalWithoutFloatDrift(): void
    {
        // C2.2 : « 37.65 » -> pourcentage 37,65 exact, ratio 0,3765 (pas de dérive float).
        $rate = Rate::fromPercentage('37.65');

        self::assertSame('37.65', $rate->percentage());
        self::assertSame('0.3765', $rate->ratio());
    }
}
