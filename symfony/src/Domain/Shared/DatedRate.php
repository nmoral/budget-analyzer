<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : un taux daté lu via un port, accompagné de la
 * description de l'entrée utilisée (clé, valeur, unité, date d'effet) pour la
 * transparence du calcul (#65). `rate()` sert au calcul, `input()` à la trace.
 */
final class DatedRate
{
    public function __construct(
        private readonly Rate $rate,
        private readonly CostInput $input,
    ) {
    }

    public function rate(): Rate
    {
        return $this->rate;
    }

    public function input(): CostInput
    {
        return $this->input;
    }
}
