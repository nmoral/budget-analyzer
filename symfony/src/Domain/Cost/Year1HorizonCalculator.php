<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Calcule l'horizon « année 1 » d'un recrutement à partir du coût annuel plein.
 *
 * Le coût année pleine (valorisé à la date de recrutement) est toujours exposé en
 * regard ; l'année 1 proratise chacun des quatre termes `moisPrésents/12` (mois
 * entiers, du recrutement à décembre inclus). Pour un calendrier scolaire, une vue
 * supplémentaire proratise sur la fenêtre sept → août (fin août incluse).
 */
final class Year1HorizonCalculator
{
    private const int DECEMBER = 12;
    private const int AUGUST = 8;

    public function __construct(private readonly AnnualCostCalculator $annualCost)
    {
    }

    public function compute(
        AgentSituation $situation,
        \DateTimeImmutable $recruitment,
        ReferenceCalendar $calendar,
    ): Year1Horizon {
        return $this->computeFrom($this->annualCost->compute($situation, $recruitment), $recruitment, $calendar);
    }

    /**
     * Applique le prorata « année 1 » à un coût annuel plein DÉJÀ valorisé (par une
     * vue de coût, #18) : le coût fourni est exposé tel quel en `fullYear`, l'année 1
     * le proratise du recrutement à décembre inclus, et la vue scolaire (sept → août)
     * est ajoutée pour un calendrier scolaire. Évite de dupliquer le prorata en
     * infrastructure.
     */
    public function computeFrom(
        AnnualCost $fullYear,
        \DateTimeImmutable $recruitment,
        ReferenceCalendar $calendar,
    ): Year1Horizon {
        $year1 = $this->proratedToMonth($fullYear, $recruitment, self::DECEMBER);

        $schoolYear = $calendar === ReferenceCalendar::SCOLAIRE
            ? $this->proratedToMonth($fullYear, $recruitment, self::AUGUST)
            : null;

        return new Year1Horizon($fullYear, $year1, $schoolYear);
    }

    private function proratedToMonth(AnnualCost $cost, \DateTimeImmutable $recruitment, int $endMonth): AnnualCost
    {
        $months = CalendarMonths::countUntil($recruitment, $endMonth);

        return new AnnualCost(
            $cost->treatmentAndPrimes()->proratedByMonths($months),
            $cost->retirement()->proratedByMonths($months),
            $cost->otherContributions()->proratedByMonths($months),
            $cost->rafp()->proratedByMonths($months),
        );
    }
}
