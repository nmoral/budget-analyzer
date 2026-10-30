<?php

declare(strict_types=1);

namespace App\Domain\Rule\Funder;

use App\Domain\Rule\AbstractRuleResolver;

/**
 * Résolveur de la famille financeur.
 *
 * @extends AbstractRuleResolver<FunderRule>
 */
final class FunderRuleResolver extends AbstractRuleResolver
{
    protected function family(): string
    {
        return 'financeur';
    }
}
