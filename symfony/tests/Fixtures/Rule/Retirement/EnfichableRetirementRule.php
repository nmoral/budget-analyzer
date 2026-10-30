<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Retirement;

use App\Domain\Rule\Retirement\RetirementRule;

/**
 * Implémentation de test taggée (code X_TEST) : prouve l'enfichage (C3.1), c.-à-d.
 * qu'ajouter une implémentation taggée la rend résolvable sans toucher au
 * moteur ni au résolveur.
 */
final class EnfichableRetirementRule implements RetirementRule
{
    public function code(): string
    {
        return 'X_TEST';
    }
}
