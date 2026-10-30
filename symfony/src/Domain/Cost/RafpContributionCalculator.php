<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\Rate;

/**
 * Terme RAFP de la formule titulaire : min(TIB·p, 0,2·TIB)·r_RAFP (C6).
 *
 * Composant FIXE de la formule (pas une famille enfichable). L'assiette est
 * plafonnée à 20 % du TIB. Le taux r_RAFP est lu via le port `DatedRateProvider`
 * sous la clé `taux_rafp` connue en dur ici ; la donnée réelle n'existant pas
 * encore (sourcing #49), la lecture lève proprement tant que la valeur manque.
 */
final class RafpContributionCalculator
{
    private const PARAMETER_KEY = 'taux_rafp';
    private const CAP_PERCENTAGE = '20';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function compute(Amount $tib, Rate $primeRate, \DateTimeImmutable $date): Amount
    {
        $primeBase = $tib->multipliedByRate($primeRate);
        $cap = $tib->multipliedByRate(Rate::fromPercentage(self::CAP_PERCENTAGE));

        return $primeBase->min($cap)->multipliedByRate($this->rates->rateAt(self::PARAMETER_KEY, $date));
    }
}
