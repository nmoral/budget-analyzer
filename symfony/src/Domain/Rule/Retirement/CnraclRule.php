<?php

declare(strict_types=1);

namespace App\Domain\Rule\Retirement;

use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;

/**
 * Régime de retraite CNRACL : expose le taux employeur daté lu depuis le
 * paramètre `taux_cnracl` via le port de domaine (aucun taux en dur). La clé de
 * paramètre est connue de la règle ; sa valeur vient des paramètres datés.
 */
final class CnraclRule implements RetirementRule
{
    private const PARAMETER_KEY = 'taux_cnracl';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function code(): string
    {
        return 'CNRACL';
    }

    public function employerRate(\DateTimeImmutable $date): DatedRate
    {
        return $this->rates->rateAt(self::PARAMETER_KEY, $date);
    }
}
