<?php

declare(strict_types=1);

namespace App\Infrastructure\Prime;

use App\Domain\Shared\MissingPrimeRateException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use App\Infrastructure\Repository\PrimeRateRepository;

/**
 * Adapter d'infrastructure branchant le port de domaine `PrimeRateProvider` sur
 * les taux de primes importés : observation retenue pour l'année de la date,
 * dans le dernier jeu de paramètres importé, mappée vers un `Rate` (ratio) et
 * son drapeau fallback.
 */
final class PrimeRateProviderAdapter implements PrimeRateProvider
{
    public function __construct(
        private readonly PrimeRateRepository $primeRates,
    ) {
    }

    public function primeRateAt(string $bodyCode, \DateTimeImmutable $date): PrimeRateObservation
    {
        $primeRate = $this->primeRates->forYear($bodyCode, (int) $date->format('Y'));

        if ($primeRate === null) {
            throw MissingPrimeRateException::forBodyAndDate($bodyCode, $date);
        }

        return new PrimeRateObservation(Rate::fromRatio($primeRate->value), $primeRate->fallback);
    }
}
