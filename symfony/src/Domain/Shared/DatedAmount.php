<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : un montant calculé à partir d'une entrée datée,
 * accompagné de la description de l'entrée source (clé, valeur, unité, date
 * d'effet) pour la transparence du calcul (#65). `amount()` sert au calcul,
 * `input()` à la trace.
 */
final class DatedAmount
{
    public function __construct(
        private readonly Amount $amount,
        private readonly CostInput $input,
    ) {
    }

    public function amount(): Amount
    {
        return $this->amount;
    }

    public function input(): CostInput
    {
        return $this->input;
    }
}
