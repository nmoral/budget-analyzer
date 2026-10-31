<?php

declare(strict_types=1);

namespace App\Infrastructure\Rate;

use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\DatedParameterRepository;

/**
 * Adapter d'infrastructure branchant le port de domaine `DatedRateProvider` sur
 * les paramètres datés (#04). Lit la valeur en vigueur à la date demandée pour
 * la clé, dans le dernier jeu de paramètres importé par défaut, et la mappe vers
 * un `Rate`. Aucun taux en dur : la valeur provient entièrement du paramètre.
 */
final class DatedRateProviderAdapter implements DatedRateProvider
{
    public function __construct(
        private readonly DatedParameterRepository $datedParameters,
        private readonly ParameterSetScope $scope,
    ) {
    }

    public function rateAt(string $key, \DateTimeImmutable $date): Rate
    {
        $parameter = $this->datedParameters->valueInEffectAt($key, $date, $this->scope->current());

        if ($parameter === null) {
            throw MissingDatedRateException::forKeyAndDate($key, $date);
        }

        return Rate::fromPercentage($parameter->value);
    }
}
