<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Rule\CostView\CostViewRule;
use App\Domain\Rule\Funder\FunderRule;
use App\Domain\Rule\Indemnitary\IndemnitaryRule;
use App\Domain\Rule\Retirement\RetirementRule;

/**
 * Résultat de la résolution d'un profil par le moteur : les quatre
 * implémentations sélectionnées (retraite, indemnitaire, financeur, vue de coût).
 */
final class ResolvedRules
{
    public function __construct(
        private readonly RetirementRule $retirement,
        private readonly IndemnitaryRule $indemnitary,
        private readonly FunderRule $funder,
        private readonly CostViewRule $costView,
    ) {
    }

    public function retirement(): RetirementRule
    {
        return $this->retirement;
    }

    public function indemnitary(): IndemnitaryRule
    {
        return $this->indemnitary;
    }

    public function funder(): FunderRule
    {
        return $this->funder;
    }

    public function costView(): CostViewRule
    {
        return $this->costView;
    }
}
