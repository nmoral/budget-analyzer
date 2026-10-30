<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Retirement;

use App\Domain\Rule\Retirement\RetirementRule;

/**
 * Implémentation de test de la famille retraite (code CAS_CIVIL). Sert à prouver
 * la résolution nominale (C2.1) sans introduire de règle métier réelle dans src/.
 */
final class CasCivilRetirementRule implements RetirementRule
{
    public function code(): string
    {
        return 'CAS_CIVIL';
    }
}
