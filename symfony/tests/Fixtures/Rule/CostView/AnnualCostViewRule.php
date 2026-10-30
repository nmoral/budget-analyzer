<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\CostView;

use App\Domain\Rule\CostView\CostViewRule;

/**
 * Implémentation de test de la famille vue de coût (code ANNEE_1).
 */
final class AnnualCostViewRule implements CostViewRule
{
    public function code(): string
    {
        return 'ANNEE_1';
    }
}
