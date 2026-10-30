<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Port de domaine PUR : fournit le taux de primes p d'un corps à une date, avec
 * son drapeau fallback. L'implémentation (adapter d'infrastructure) retient la
 * dernière observation dont l'année est au plus celle de la date, scopée au jeu
 * de paramètres.
 */
interface PrimeRateProvider
{
    /**
     * @throws MissingPrimeRateException si aucune observation ne précède la date
     */
    public function primeRateAt(string $bodyCode, \DateTimeImmutable $date): PrimeRateObservation;
}
