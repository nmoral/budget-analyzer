<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Amount;

/**
 * Vue budgétaire (#18, vue par défaut) : valorise la retraite comme la dépense
 * employeur effective, retraite = TIB × employerRate(régime, date), via la règle
 * de régime existante. C'est la méthode déjà appliquée par AnnualCostCalculator :
 * la surcouche en BUDGETAIRE est donc strictement égale au moteur de base.
 *
 * Aucune hypothèse (assumptions vide) : la vue ne repose que sur le taux employeur
 * daté du régime, lui-même issu des paramètres datés.
 */
final class BudgetViewRule implements CostViewRule
{
    public function __construct(
        private readonly RetirementRuleResolver $retirementRules,
    ) {
    }

    public function code(): string
    {
        return 'BUDGETAIRE';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): Amount
    {
        $regime = $this->retirementRules->resolve($retirementRegimeCode);

        return $tib->multipliedByRate($regime->employerRate($date));
    }

    public function definition(): string
    {
        return 'Coût retraite = dépense employeur effective : TIB × taux de cotisation employeur du régime à la date simulée.';
    }

    public function question(): string
    {
        return 'Combien l\'employeur verse-t-il réellement au titre de la retraite cette année ?';
    }

    public function assumptions(): array
    {
        return [];
    }

    public function sources(): array
    {
        return [
            'Taux de cotisation employeur CAS « Pensions civiles » (loi de finances).',
            'Taux de cotisation employeur CNRACL (décret annuel).',
        ];
    }
}
