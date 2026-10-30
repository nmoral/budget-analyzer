<?php

declare(strict_types=1);

namespace App\Domain\Rule\Retirement;

use App\Domain\Rule\Rule;
use App\Domain\Shared\Rate;

/**
 * Famille « retraite » : régime appliqué au profil. Au-delà de l'identité de
 * sélection (`code()`, #12), la règle expose sa signature de calcul : le taux
 * employeur retraite à une date donnée.
 */
interface RetirementRule extends Rule
{
    /**
     * Taux employeur retraite applicable à la date donnée.
     */
    public function employerRate(\DateTimeImmutable $date): Rate;
}
