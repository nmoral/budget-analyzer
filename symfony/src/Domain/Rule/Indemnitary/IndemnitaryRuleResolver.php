<?php

declare(strict_types=1);

namespace App\Domain\Rule\Indemnitary;

use App\Domain\Rule\AbstractRuleResolver;

/**
 * Résolveur de la famille indemnitaire.
 *
 * @extends AbstractRuleResolver<IndemnitaryRule>
 */
final class IndemnitaryRuleResolver extends AbstractRuleResolver
{
    protected function family(): string
    {
        return 'indemnitaire';
    }
}
