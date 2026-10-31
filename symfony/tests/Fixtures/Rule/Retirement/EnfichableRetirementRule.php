<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Retirement;

use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Shared\CostInput;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\Rate;

/**
 * Implémentation de test taggée (code X_TEST) : prouve l'enfichage (C3.1), c.-à-d.
 * qu'ajouter une implémentation taggée la rend résolvable sans toucher au
 * moteur ni au résolveur. Le taux renvoyé est déterministe et factice.
 */
final class EnfichableRetirementRule implements RetirementRule
{
    public function code(): string
    {
        return 'X_TEST';
    }

    public function employerRate(\DateTimeImmutable $date): DatedRate
    {
        return new DatedRate(
            Rate::fromPercentage('0'),
            new CostInput('taux_x_test', '0', 'PCT_TIB', $date),
        );
    }
}
