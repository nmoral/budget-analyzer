<?php

declare(strict_types=1);

namespace App\Domain\Rule\OtherContributions;

use App\Domain\Rule\Rule;
use App\Domain\Shared\Rate;

/**
 * Famille « autres cotisations » (r_autres) : cotisations employeur hors retraite
 * appliquées au traitement, indexées par versant (FPE, FPH). Au-delà de l'identité
 * de sélection (`code()` = versant), la règle expose sa signature de calcul : le
 * taux r_autres à une date donnée.
 */
interface OtherContributionsRule extends Rule
{
    /**
     * Taux « autres cotisations » applicable à la date donnée.
     */
    public function rate(\DateTimeImmutable $date): Rate;
}
