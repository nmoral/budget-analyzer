<?php

declare(strict_types=1);

namespace App\Domain\Rule\Retirement;

use App\Domain\Rule\AbstractRuleResolver;

/**
 * Résolveur de la famille retraite.
 *
 * @extends AbstractRuleResolver<RetirementRule>
 */
final class RetirementRuleResolver extends AbstractRuleResolver
{
    protected function family(): string
    {
        return 'retraite';
    }
}
