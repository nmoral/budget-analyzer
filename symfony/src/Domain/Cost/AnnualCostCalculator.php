<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\PrimeRateProvider;

/**
 * Moteur de coût employeur annuel d'un agent titulaire à une date simulée (C5).
 *
 * C = TIB·(1+p) + TIB·r_retraite + TIB·(1+p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP
 *
 * Assemble les composants datés via les ports du domaine : TIB (indice majoré ×
 * point d'indice), p (taux de primes + drapeau fallback), r_retraite (règle du
 * régime), r_autres (règle du versant) et RAFP (composant fixe plafonné). Pure
 * précision : chaque produit est arrondi half-up à 6 décimales dans `Amount`, le
 * total est la somme exacte des quatre termes (aucun arrondi métier).
 */
final class AnnualCostCalculator
{
    public function __construct(
        private readonly IndexedSalaryCalculator $indexedSalary,
        private readonly PrimeRateProvider $primeRates,
        private readonly RetirementRuleResolver $retirementRules,
        private readonly OtherContributionsRuleResolver $otherContributions,
        private readonly RafpContributionCalculator $rafp,
    ) {
    }

    public function compute(AgentSituation $situation, \DateTimeImmutable $date): AnnualCost
    {
        $tib = $this->indexedSalary->compute($situation->increasedIndex(), $date);
        $primeRate = $this->primeRates->primeRateAt($situation->bodyCode(), $date)->rate();

        $treatmentAndPrimes = $tib->plus($tib->multipliedByRate($primeRate));

        $retirementRule = $this->retirementRules->resolve($situation->retirementRegimeCode());
        $retirement = $tib->multipliedByRate($retirementRule->employerRate($date));

        $otherRule = $this->otherContributions->resolve($situation->versant());
        $other = $treatmentAndPrimes->multipliedByRate($otherRule->rate($date));

        $rafp = $this->rafp->compute($tib, $primeRate, $date);

        return new AnnualCost($treatmentAndPrimes, $retirement, $other, $rafp);
    }
}
