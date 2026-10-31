<?php

declare(strict_types=1);

namespace App\Infrastructure\Prime;

use App\Domain\Shared\CostInput;
use App\Domain\Shared\MissingPrimeRateException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\PrimeRateRepository;
use App\Infrastructure\Transparency\SourceCapture;

/**
 * Adapter d'infrastructure branchant le port de domaine `PrimeRateProvider` sur
 * les taux de primes importés : observation retenue pour l'année de la date,
 * dans le dernier jeu de paramètres importé, mappée vers un `Rate` (ratio), son
 * drapeau fallback et l'entrée tracée. Enregistre au passage la correspondance
 * (clé, date d'effet) → `Source` dans le pont de capture, pour la transparence (#65).
 *
 * L'entrée tracée d'une prime porte une clé synthétique `taux_primes_{corps}` et
 * une date d'effet au 1er janvier de l'année d'observation (les primes ne sont pas
 * des paramètres datés par période, mais observées par année).
 */
final class PrimeRateProviderAdapter implements PrimeRateProvider
{
    public function __construct(
        private readonly PrimeRateRepository $primeRates,
        private readonly ParameterSetScope $scope,
        private readonly SourceCapture $sourceCapture,
    ) {
    }

    public function primeRateAt(string $bodyCode, \DateTimeImmutable $date): PrimeRateObservation
    {
        $primeRate = $this->primeRates->forYear($bodyCode, (int) $date->format('Y'), $this->scope->current());

        if ($primeRate === null) {
            throw MissingPrimeRateException::forBodyAndDate($bodyCode, $date);
        }

        $key = sprintf('taux_primes_%s', $bodyCode);
        $effectiveDate = new \DateTimeImmutable(sprintf('%d-01-01', $primeRate->observationYear));
        $this->sourceCapture->record($key, $effectiveDate, $primeRate->source);

        return new PrimeRateObservation(
            Rate::fromRatio($primeRate->value),
            $primeRate->fallback,
            new CostInput($key, $primeRate->value, 'RATIO', $effectiveDate),
        );
    }
}
