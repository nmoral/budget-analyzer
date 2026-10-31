<?php

declare(strict_types=1);

namespace App\Infrastructure\Amount;

use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\DatedParameterRepository;

/**
 * Adapter d'infrastructure branchant le port de domaine `DatedAmountProvider`
 * sur les paramètres datés. Lit la valeur en vigueur à la date pour la clé, dans
 * le dernier jeu de paramètres importé, et la mappe vers un `Amount`.
 */
final class DatedAmountProviderAdapter implements DatedAmountProvider
{
    public function __construct(
        private readonly DatedParameterRepository $datedParameters,
        private readonly ParameterSetScope $scope,
    ) {
    }

    public function amountAt(string $key, \DateTimeImmutable $date): Amount
    {
        $parameter = $this->datedParameters->valueInEffectAt($key, $date, $this->scope->current());

        if ($parameter === null) {
            throw MissingDatedAmountException::forKeyAndDate($key, $date);
        }

        return Amount::fromString($parameter->value);
    }
}
