<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Stratégie de coût d'échelon « view-aware » (#66, C1.2) : valorise chaque échelon
 * selon une vue de coût (#18) via `CostViewCalculator`. Seul le terme retraite suit
 * la vue, les trois autres restent ceux du moteur de base — exactement comme year1.
 *
 * Injectée au `PermanentRegimeCalculator` pour que la moyenne pondérée par échelon
 * du régime permanent soit calculée sur des coûts view-aware, sans dupliquer la
 * logique de moyenne.
 */
final class ViewAwareStepCost implements StepCostCalculator
{
    public function __construct(
        private readonly CostViewCalculator $viewCalculator,
        private readonly string $costViewCode,
    ) {
    }

    public function compute(AgentSituation $situation, \DateTimeImmutable $date): AnnualCost
    {
        return $this->viewCalculator->compute($situation, $date, $this->costViewCode);
    }
}
