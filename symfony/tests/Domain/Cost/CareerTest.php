<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use PHPUnit\Framework\TestCase;

/**
 * Value object de domaine PUR `Career` : suite d'échelons (`CareerStep`) ; durée
 * totale = somme des durées, carrière non vide exigée (C4).
 */
final class CareerTest extends TestCase
{
    public function testTotalMonthsSumsStepDurations(): void
    {
        // C4.3 : Career de 2 CareerStep (12 et 48 mois) → totalMonths() = 60.
        $career = new Career(
            new CareerStep($this->situation(), 12),
            new CareerStep($this->situation(), 48),
        );

        self::assertSame(60, $career->totalMonths());
    }

    public function testTotalMonthsOfSingleStep(): void
    {
        $career = new Career(new CareerStep($this->situation(), 24));

        self::assertSame(24, $career->totalMonths());
    }

    public function testExposesItsSteps(): void
    {
        $first = new CareerStep($this->situation(), 12);
        $second = new CareerStep($this->situation(), 48);
        $career = new Career($first, $second);

        self::assertSame([$first, $second], $career->steps());
    }

    public function testEmptyCareerThrows(): void
    {
        // C4.5 : Career() sans step → erreur.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('au moins un échelon');

        new Career();
    }

    private function situation(): AgentSituation
    {
        return new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');
    }
}
