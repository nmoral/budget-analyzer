<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Rule\AbstractRuleResolver;

/**
 * Résolveur de la famille vue de coût.
 *
 * @extends AbstractRuleResolver<CostViewRule>
 */
final class CostViewRuleResolver extends AbstractRuleResolver
{
    protected function family(): string
    {
        return 'vue de coût';
    }
}
