<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : taux de primes p retenu pour un corps, et
 * drapeau indiquant s'il provient d'une observation de repli (fallback),
 * exposé pour la transparence du calcul. Porte aussi la description de l'entrée
 * utilisée (clé, valeur, unité, date d'effet) pour la trace (#65).
 */
final class PrimeRateObservation
{
    public function __construct(
        private readonly Rate $rate,
        private readonly bool $fallback,
        private readonly CostInput $input,
    ) {
    }

    public function rate(): Rate
    {
        return $this->rate;
    }

    public function isFallback(): bool
    {
        return $this->fallback;
    }

    public function input(): CostInput
    {
        return $this->input;
    }
}
