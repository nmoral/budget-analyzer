<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR : série annuelle du coût d'une cohorte.
 *
 * Porte le coût annuel d'un agent pour chaque année de l'horizon (`perYear()`,
 * année _k_ = mois [(k-1)·12, k·12) depuis l'entrée), déjà valorisé par le
 * calculateur (avancement d'échelon, prorata intra-année, point gelé). Le VO
 * n'embarque aucune logique de calcul métier ; les projections sur l'effectif et
 * les cumuls viendront s'appuyer sur cette série sans la recalculer.
 */
final class CohortCostSeries
{
    /**
     * @var list<AnnualCost>
     */
    private readonly array $perYear;

    /**
     * @param list<AnnualCost> $perYear coût annuel de chaque année de l'horizon
     */
    public function __construct(array $perYear)
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
}
