<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;

/**
 * Value object de domaine PUR : série annuelle du coût d'une cohorte.
 *
 * Porte le coût annuel d'un agent pour chaque année de l'horizon (`perYear()`,
 * année _k_ = mois [(k-1)·12, k·12) depuis l'entrée), déjà valorisé par le
 * calculateur (avancement d'échelon, prorata intra-année, point gelé), et
 * l'effectif N de la cohorte. Les projections sur l'effectif (`forHeadcount()`)
 * et les cumuls (`totalPerAgent()`, `totalForHeadcount()`) se déduisent terme par
 * terme de la série, sans la recalculer.
 */
final class CohortCostSeries
{
    /**
     * @var list<AnnualCost>
     */
    private readonly array $perYear;

    /**
     * @param list<AnnualCost> $perYear   coût annuel de chaque année de l'horizon
     * @param int              $headcount effectif de la cohorte (N)
     */
    public function __construct(array $perYear, private readonly int $headcount)
    {
        $this->perYear = $perYear;
    }

    /**
     * Coût annuel d'un agent, année par année sur tout l'horizon.
     *
     * @return list<AnnualCost>
     */
    public function perYear(): array
    {
        return $this->perYear;
    }

    /**
     * Coût annuel de la cohorte, année par année : chaque terme × N (le total de
     * chaque `AnnualCost` se recompose comme somme des termes × N).
     *
     * @return list<AnnualCost>
     */
    public function forHeadcount(): array
    {
        return array_map(fn (AnnualCost $year): AnnualCost => $this->scaled($year), $this->perYear);
    }

    /**
     * Coût cumulé d'un agent sur tout l'horizon : somme terme par terme des années.
     */
    public function totalPerAgent(): AnnualCost
    {
        $treatmentAndPrimes = Amount::fromString('0');
        $retirement = Amount::fromString('0');
        $otherContributions = Amount::fromString('0');
        $rafp = Amount::fromString('0');

        foreach ($this->perYear as $year) {
            $treatmentAndPrimes = $treatmentAndPrimes->plus($year->treatmentAndPrimes());
            $retirement = $retirement->plus($year->retirement());
            $otherContributions = $otherContributions->plus($year->otherContributions());
            $rafp = $rafp->plus($year->rafp());
        }

        return new AnnualCost($treatmentAndPrimes, $retirement, $otherContributions, $rafp);
    }

    /**
     * Coût cumulé de la cohorte sur tout l'horizon : `totalPerAgent()` × N terme par terme.
     */
    public function totalForHeadcount(): AnnualCost
    {
        return $this->scaled($this->totalPerAgent());
    }

    /**
     * Projette un coût annuel sur l'effectif : chaque terme × N.
     */
    private function scaled(AnnualCost $cost): AnnualCost
    {
        return new AnnualCost(
            $cost->treatmentAndPrimes()->multipliedByIndex($this->headcount),
            $cost->retirement()->multipliedByIndex($this->headcount),
            $cost->otherContributions()->multipliedByIndex($this->headcount),
            $cost->rafp()->multipliedByIndex($this->headcount),
        );
    }
}
