<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Rule\CostView\CostViewRuleResolver;

/**
 * Surcouche « vue de coût » (#18) : calcule le coût annuel d'un agent selon une
 * vue enfichable sélectionnée par code, sans altérer AnnualCostCalculator.
 *
 * Seul le terme retraite est remplacé par celui de la vue (resolveur de la famille
 * cost_view) ; les trois autres termes (traitement + primes, autres cotisations,
 * RAFP) restent ceux calculés par AnnualCostCalculator. La surcouche calcule le TIB
 * via IndexedSalaryCalculator et le passe à la vue (signature en types Shared, pour
 * éviter un cycle de dépendances domaine Cost ↔ Rule).
 */
final class CostViewCalculator
{
    public function __construct(
        private readonly AnnualCostCalculator $baseCalculator,
        private readonly IndexedSalaryCalculator $indexedSalary,
        private readonly CostViewRuleResolver $costViews,
    ) {
    }

    public function compute(AgentSituation $situation, \DateTimeImmutable $date, string $costViewCode): AnnualCost
    {
        $base = $this->baseCalculator->compute($situation, $date);

        $view = $this->costViews->resolve($costViewCode);
        $tib = $this->indexedSalary->compute($situation->increasedIndex(), $date);
        $valuation = $view->retirementCost($tib, $date, $situation->retirementRegimeCode());

        $baseTrace = $base->trace();
        $trace = $baseTrace === null ? null : new CostTrace(
            $baseTrace->treatmentAndPrimes(),
            new TermTrace($valuation->formula(), $valuation->inputs()),
            $baseTrace->otherContributions(),
            $baseTrace->rafp(),
        );

        return new AnnualCost(
            $base->treatmentAndPrimes(),
            $valuation->amount(),
            $base->otherContributions(),
            $base->rafp(),
            $trace,
        );
    }
}
