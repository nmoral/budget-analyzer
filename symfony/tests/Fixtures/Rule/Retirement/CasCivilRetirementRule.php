<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Retirement;

use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Shared\Rate;

/**
 * Implémentation de test de la famille retraite (code CAS_CIVIL). Sert à prouver
 * la résolution nominale (C2.1) sans introduire de règle métier réelle dans src/.
 * Le taux renvoyé est déterministe et factice.
 */
final class CasCivilRetirementRule implements RetirementRule
{
    public function code(): string
    {
        return 'CAS_CIVIL';
    }

    public function employerRate(\DateTimeImmutable $date): Rate
    {
        return Rate::fromPercentage('82.28');
    }
}
