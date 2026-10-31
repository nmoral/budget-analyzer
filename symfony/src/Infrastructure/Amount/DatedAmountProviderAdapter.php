<?php

declare(strict_types=1);

namespace App\Infrastructure\Amount;

use App\Domain\Shared\Amount;
use App\Domain\Shared\CostInput;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\DatedParameterRepository;
use App\Infrastructure\Transparency\SourceCapture;

/**
 * Adapter d'infrastructure branchant le port de domaine `DatedAmountProvider`
 * sur les paramètres datés. Lit la valeur en vigueur à la date pour la clé, dans
 * le dernier jeu de paramètres importé, et la mappe vers un `DatedAmount` (montant
 * + entrée tracée). Enregistre au passage la correspondance (clé, date d'effet) →
 * `Source` dans le pont de capture, pour la transparence (#65).
 */
final class DatedAmountProviderAdapter implements DatedAmountProvider
{
    public function __construct(
        private readonly DatedParameterRepository $datedParameters,
        private readonly ParameterSetScope $scope,
        private readonly SourceCapture $sourceCapture,
    ) {
    }

    public function amountAt(string $key, \DateTimeImmutable $date): DatedAmount
    {
        $parameter = $this->datedParameters->valueInEffectAt($key, $date, $this->scope->current());

        if ($parameter === null) {
            throw MissingDatedAmountException::forKeyAndDate($key, $date);
        }

        $this->sourceCapture->record($parameter->key, $parameter->startDate, $parameter->source);

        return new DatedAmount(
            Amount::fromString($parameter->value),
            new CostInput($parameter->key, $parameter->value, $parameter->unit, $parameter->startDate),
        );
    }
}
