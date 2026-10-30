<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : taux de primes p retenu pour un corps, et
 * drapeau indiquant s'il provient d'une observation de repli (fallback),
 * exposé pour la transparence du calcul.
 */
final class PrimeRateObservation
{
    public function __construct(
        private readonly Rate $rate,
        private readonly bool $fallback,
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
}
