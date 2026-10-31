<?php

declare(strict_types=1);

namespace App\Domain\Rule\Retirement;

use App\Domain\Rule\Rule;
use App\Domain\Shared\DatedRate;

/**
 * Famille « retraite » : régime appliqué au profil. Au-delà de l'identité de
 * sélection (`code()`, #12), la règle expose sa signature de calcul : le taux
 * employeur retraite daté à une date donnée (taux + entrée tracée, #65).
 */
interface RetirementRule extends Rule
{
    /**
     * Taux employeur retraite applicable à la date donnée, avec l'entrée tracée.
     */
    public function employerRate(\DateTimeImmutable $date): DatedRate;
}
