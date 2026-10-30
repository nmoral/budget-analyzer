<?php

declare(strict_types=1);

namespace App\Domain\Rule\OtherContributions;

use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\Rate;

/**
 * Autres cotisations du versant FPE : expose le taux r_autres daté lu depuis le
 * paramètre `taux_autres_cotisations_fpe` via le port de domaine (aucun taux en
 * dur). La clé est connue de la règle ; sa valeur vient des paramètres datés. La
 * donnée réelle n'existant pas encore (sourcing #49), la règle lève proprement
 * tant qu'aucune valeur ne couvre la date.
 */
final class FpeOtherContributionsRule implements OtherContributionsRule
{
    private const PARAMETER_KEY = 'taux_autres_cotisations_fpe';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function code(): string
    {
        return 'FPE';
    }

    public function rate(\DateTimeImmutable $date): Rate
    {
        return $this->rates->rateAt(self::PARAMETER_KEY, $date);
    }
}
