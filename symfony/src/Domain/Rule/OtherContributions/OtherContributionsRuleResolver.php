<?php

declare(strict_types=1);

namespace App\Domain\Rule\OtherContributions;

use App\Domain\Rule\AbstractRuleResolver;

/**
 * Résolveur de la famille « autres cotisations » (r_autres), indexée par versant.
 *
 * @extends AbstractRuleResolver<OtherContributionsRule>
 */
final class OtherContributionsRuleResolver extends AbstractRuleResolver
{
    protected function family(): string
    {
        return 'autres cotisations';
    }
}
