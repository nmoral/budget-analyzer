<?php

declare(strict_types=1);

namespace App\Domain\Rule\Retirement;

use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\Rate;

/**
 * Régime de retraite CAS civil : expose le taux employeur daté lu depuis le
 * paramètre `taux_cas_civil` via le port de domaine (aucun taux en dur). La clé
 * de paramètre est connue de la règle ; sa valeur vient des paramètres datés.
 */
final class CasCivilRule implements RetirementRule
{
    private const PARAMETER_KEY = 'taux_cas_civil';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function code(): string
    {
        return 'CAS_CIVIL';
    }

    public function employerRate(\DateTimeImmutable $date): Rate
    {
        return $this->rates->rateAt(self::PARAMETER_KEY, $date);
    }
}
