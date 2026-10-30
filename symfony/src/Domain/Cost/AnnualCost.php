<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;

/**
 * Value object de domaine PUR : décomposition du coût employeur annuel d'un agent
 * en ses quatre termes, plus leur total.
 *
 * C = TIB·(1+p) + TIB·r_retraite + TIB·(1+p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP
 *
 * Le total est la somme EXACTE des quatre termes (aucun arrondi métier ; l'arrondi
 * half-up n'intervient que sur chaque produit `Amount`, cf. #19/#20 pour l'affichage).
 */
final class AnnualCost
{
    private readonly Amount $total;

    public function __construct(
        private readonly Amount $treatmentAndPrimes,
        private readonly Amount $retirement,
        private readonly Amount $otherContributions,
        private readonly Amount $rafp,
    ) {
        $this->total = $treatmentAndPrimes
            ->plus($retirement)
            ->plus($otherContributions)
            ->plus($rafp);
    }

    /**
     * Terme « traitement + primes » = TIB·(1+p).
     */
    public function treatmentAndPrimes(): Amount
    {
        return $this->treatmentAndPrimes;
    }

    /**
     * Terme « retraite » = TIB·r_retraite.
     */
    public function retirement(): Amount
    {
        return $this->retirement;
    }

    /**
     * Terme « autres cotisations » = TIB·(1+p)·r_autres.
     */
    public function otherContributions(): Amount
    {
        return $this->otherContributions;
    }

    /**
     * Terme « RAFP » = min(TIB·p, 0,2·TIB)·r_RAFP.
     */
    public function rafp(): Amount
    {
        return $this->rafp;
    }

    /**
     * Total = somme exacte des quatre termes.
     */
    public function total(): Amount
    {
        return $this->total;
    }
}
