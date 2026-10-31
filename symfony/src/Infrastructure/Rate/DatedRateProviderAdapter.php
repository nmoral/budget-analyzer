<?php

declare(strict_types=1);

namespace App\Infrastructure\Rate;

use App\Domain\Shared\CostInput;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\DatedParameterRepository;
use App\Infrastructure\Transparency\SourceCapture;

/**
 * Adapter d'infrastructure branchant le port de domaine `DatedRateProvider` sur
 * les paramètres datés (#04). Lit la valeur en vigueur à la date demandée pour
 * la clé, dans le dernier jeu de paramètres importé par défaut, et la mappe vers
 * un `DatedRate` (taux + entrée tracée). Aucun taux en dur : la valeur provient
 * entièrement du paramètre. Enregistre au passage la correspondance (clé, date
 * d'effet) → `Source` dans le pont de capture, pour la transparence (#65).
 */
final class DatedRateProviderAdapter implements DatedRateProvider
{
    public function __construct(
        private readonly DatedParameterRepository $datedParameters,
        private readonly ParameterSetScope $scope,
        private readonly SourceCapture $sourceCapture,
    ) {
    }

    public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
    {
        $parameter = $this->datedParameters->valueInEffectAt($key, $date, $this->scope->current());

        if ($parameter === null) {
            throw MissingDatedRateException::forKeyAndDate($key, $date);
        }

        $this->sourceCapture->record($parameter->key, $parameter->startDate, $parameter->source);

        return new DatedRate(
            Rate::fromPercentage($parameter->value),
            new CostInput($parameter->key, $parameter->value, $parameter->unit, $parameter->startDate),
        );
    }
}
