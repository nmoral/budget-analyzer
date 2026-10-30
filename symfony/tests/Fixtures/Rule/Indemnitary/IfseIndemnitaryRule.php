<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Indemnitary;

use App\Domain\Rule\Indemnitary\IndemnitaryRule;

/**
 * Implémentation de test de la famille indemnitaire (code IFSE).
 */
final class IfseIndemnitaryRule implements IndemnitaryRule
{
    public function code(): string
    {
        return 'IFSE';
    }
}
