<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Stratégie de coût d'un échelon pour la moyenne pondérée du régime permanent :
 * calcule le coût annuel d'une situation d'agent à une date. Permet de valoriser
 * chaque échelon soit en coût de base (`AnnualCostCalculator`), soit selon une vue
 * de coût (`ViewAwareStepCost`), sans dupliquer la moyenne pondérée.
 */
interface StepCostCalculator
{
    public function compute(AgentSituation $situation, \DateTimeImmutable $date): AnnualCost;
}
