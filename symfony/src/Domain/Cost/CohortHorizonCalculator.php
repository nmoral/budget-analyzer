<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;

/**
 * Calcule la série annuelle du coût d'une cohorte : pour chaque année de l'horizon,
 * le coût annuel d'un agent dont l'échelon progresse selon les durées des `CareerStep`.
 *
 * Avancement mensuel : l'échelon actif au mois _m_ (0-indexé depuis l'entrée) est
 * déterminé par les durées cumulées des échelons ; le dernier échelon est terminal
 * (sa durée propre est ignorée, l'agent y reste indéfiniment, sans départ).
 *
 * Coût d'une année = `Σ sur les 12 mois (coût annuel de l'échelon actif ce mois) / 12`,
 * terme par terme (chacun des quatre termes de l'`AnnualCost`). Point gelé : toutes
 * les années sont valorisées à la date d'entrée, seul l'échelon évolue.
 */
final class CohortHorizonCalculator
{
    private const int MONTHS_PER_YEAR = 12;

    public function __construct(private readonly AnnualCostCalculator $annualCost)
    {
    }

    public function compute(Career $career, \DateTimeImmutable $entry, int $horizonYears, int $headcount = 1): CohortCostSeries
    {
        if ($horizonYears < 1) {
            throw new \InvalidArgumentException(sprintf(
                'L\'horizon doit être au moins égal à 1 an, %d reçu.',
                $horizonYears,
            ));
        }

        if ($headcount < 1) {
            throw new \InvalidArgumentException(sprintf(
                'L\'effectif doit être au moins égal à 1, %d reçu.',
                $headcount,
            ));
        }

        $steps = $career->steps();
        $lastIndex = count($steps) - 1;

        // Point gelé : coût annuel de chaque échelon valorisé une seule fois, à la date d'entrée.
        $stepCosts = [];
        foreach ($steps as $step) {
            $stepCosts[] = $this->annualCost->compute($step->situation(), $entry);
        }

        $perYear = [];
        for ($year = 0; $year < $horizonYears; ++$year) {
            $perYear[] = $this->yearCost($steps, $lastIndex, $stepCosts, $year);
        }

        return new CohortCostSeries($perYear, $headcount);
    }

    /**
     * Mode « carrière complète » : dérive l'horizon couvrant toute la progression
     * jusqu'au terminal, `horizonYears = ⌈Σ(durées des échelons non terminaux) / 12⌉`
     * (arrondi au supérieur), puis délègue à `compute(...)`.
     *
     * Le dernier `CareerStep` est le terminal : sa durée est exclue de la somme. Une
     * carrière réduite au seul terminal donne Σ = 0 ; l'horizon est alors ramené à 1 an
     * (série minimale non vide) plutôt que de violer la garde `horizon ≥ 1`.
     */
    public function computeFullCareer(Career $career, \DateTimeImmutable $entry, int $headcount = 1): CohortCostSeries
    {
        $steps = $career->steps();
        $lastIndex = count($steps) - 1;

        $nonTerminalMonths = 0;
        for ($i = 0; $i < $lastIndex; ++$i) {
            $nonTerminalMonths += $steps[$i]->durationMonths();
        }

        $horizonYears = max(1, intdiv($nonTerminalMonths + self::MONTHS_PER_YEAR - 1, self::MONTHS_PER_YEAR));

        return $this->compute($career, $entry, $horizonYears, $headcount);
    }

    /**
     * Coût d'une année : moyenne terme par terme des coûts d'échelon actifs sur ses 12 mois.
     *
     * @param list<CareerStep> $steps
     * @param list<AnnualCost> $stepCosts
     */
    private function yearCost(array $steps, int $lastIndex, array $stepCosts, int $year): AnnualCost
    {
        $treatmentAndPrimes = Amount::fromString('0');
        $retirement = Amount::fromString('0');
        $otherContributions = Amount::fromString('0');
        $rafp = Amount::fromString('0');

        for ($monthInYear = 0; $monthInYear < self::MONTHS_PER_YEAR; ++$monthInYear) {
            $month = $year * self::MONTHS_PER_YEAR + $monthInYear;
            $cost = $stepCosts[$this->activeStepIndex($steps, $lastIndex, $month)];

            $treatmentAndPrimes = $treatmentAndPrimes->plus($cost->treatmentAndPrimes());
            $retirement = $retirement->plus($cost->retirement());
            $otherContributions = $otherContributions->plus($cost->otherContributions());
            $rafp = $rafp->plus($cost->rafp());
        }

        return new AnnualCost(
            $treatmentAndPrimes->dividedBy(self::MONTHS_PER_YEAR),
            $retirement->dividedBy(self::MONTHS_PER_YEAR),
            $otherContributions->dividedBy(self::MONTHS_PER_YEAR),
            $rafp->dividedBy(self::MONTHS_PER_YEAR),
        );
    }

    /**
     * Indice de l'échelon actif au mois donné : step0 couvre [0, d0), step1 [d0, d0+d1),
     * etc. ; une fois les échelons non terminaux épuisés, le dernier (terminal) reste actif.
     *
     * @param list<CareerStep> $steps
     */
    private function activeStepIndex(array $steps, int $lastIndex, int $month): int
    {
        $cumulative = 0;
        for ($i = 0; $i < $lastIndex; ++$i) {
            $cumulative += $steps[$i]->durationMonths();
            if ($month < $cumulative) {
                return $i;
            }
        }

        return $lastIndex;
    }
}
