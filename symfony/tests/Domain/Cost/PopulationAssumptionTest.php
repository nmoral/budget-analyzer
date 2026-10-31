<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\PopulationAssumption;
use PHPUnit\Framework\TestCase;

/**
 * Enum de domaine PUR `PopulationAssumption` : hypothèse de population, sans
 * logique de calcul (C4).
 */
final class PopulationAssumptionTest extends TestCase
{
    public function testExposesTheStationaryCase(): void
    {
        self::assertContains(PopulationAssumption::STATIONARY, PopulationAssumption::cases());
    }

    public function testStationaryIsTheOnlyCase(): void
    {
        self::assertSame([PopulationAssumption::STATIONARY], PopulationAssumption::cases());
    }
}
