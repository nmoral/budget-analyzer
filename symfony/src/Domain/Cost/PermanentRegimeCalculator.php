<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;

/**
 * Calcule le coût en régime permanent (population stationnaire) d'un corps : le
 * coût annuel moyen d'un agent est la moyenne, pondérée par la durée de chaque
 * échelon, des coûts d'échelon valorisés à la date, puis projeté sur N postes.
 *
 * La moyenne est appliquée terme par terme : pour chacun des quatre termes du coût
 * annuel, `Σ(terme_i × duréeMois_i) / Σ(duréeMois_i)`. Le numérateur est un produit
 * exact par un entier (`multipliedByIndex`), seul le quotient final est arrondi.
 */
final class PermanentRegimeCalculator
{
    public function __construct(private readonly AnnualCostCalculator $annualCost)
    {
    }

    public function compute(Career $career, \DateTimeImmutable $date, int $headcount): PermanentRegimeCost
    {
        if ($headcount < 1) {
            throw new \InvalidArgumentException(sprintf(
                'L\'effectif doit être au moins égal à 1, %d reçu.',
                $headcount,
            ));
        }

        $totalMonths = $career->totalMonths();

        $treatmentAndPrimes = Amount::fromString('0');
        $retirement = Amount::fromString('0');
        $otherContributions = Amount::fromString('0');
        $rafp = Amount::fromString('0');

        foreach ($career->steps() as $step) {
            $cost = $this->annualCost->compute($step->situation(), $date);
            $months = $step->durationMonths();

            $treatmentAndPrimes = $treatmentAndPrimes->plus($cost->treatmentAndPrimes()->multipliedByIndex($months));
            $retirement = $retirement->plus($cost->retirement()->multipliedByIndex($months));
            $otherContributions = $otherContributions->plus($cost->otherContributions()->multipliedByIndex($months));
            $rafp = $rafp->plus($cost->rafp()->multipliedByIndex($months));
        }

        $averagePerAgent = new AnnualCost(
            $treatmentAndPrimes->dividedBy($totalMonths),
            $retirement->dividedBy($totalMonths),
            $otherContributions->dividedBy($totalMonths),
            $rafp->dividedBy($totalMonths),
        );

        return new PermanentRegimeCost($averagePerAgent, $headcount);
    }
}
