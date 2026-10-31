<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Trace de calcul PURE du coût annuel (#65) : une `TermTrace` par terme de la
 * formule C = TIB·(1+p) + TIB·r_retraite + TIB·(1+p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP.
 *
 * Value object PUR, sans dépendance à `Source` ni à la persistance : la
 * correspondance entrée → source est reconstituée hors du domaine (#J3).
 */
final class CostTrace
{
    public function __construct(
        private readonly TermTrace $treatmentAndPrimes,
        private readonly TermTrace $retirement,
        private readonly TermTrace $otherContributions,
        private readonly TermTrace $rafp,
    ) {
    }

    public function treatmentAndPrimes(): TermTrace
    {
        return $this->treatmentAndPrimes;
    }

    public function retirement(): TermTrace
    {
        return $this->retirement;
    }

    public function otherContributions(): TermTrace
    {
        return $this->otherContributions;
    }

    public function rafp(): TermTrace
    {
        return $this->rafp;
    }
}
