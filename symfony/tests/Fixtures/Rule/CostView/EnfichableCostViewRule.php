<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\CostView;

use App\Domain\Rule\CostView\CostViewRule;
use App\Domain\Shared\Amount;
use App\Domain\Shared\Rate;
use App\Domain\Shared\RetirementValuation;

/**
 * Implémentation de test taggée (code X_TEST_VIEW) : prouve l'enfichabilité (C7),
 * c.-à-d. qu'ajouter une 5ᵉ vue taggée la rend résolue et appliquée par la surcouche
 * sans modifier le moteur ni le résolveur. Terme retraite déterministe (TIB × 10 %).
 */
final class EnfichableCostViewRule implements CostViewRule
{
    public function code(): string
    {
        return 'X_TEST_VIEW';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): RetirementValuation
    {
        return new RetirementValuation($tib->multipliedByRate(Rate::fromPercentage('10')), 'TIB·10%', []);
    }

    public function definition(): string
    {
        return 'Vue de test enfichable : TIB × 10 %.';
    }

    public function question(): string
    {
        return 'Vue de test enfichable ?';
    }

    public function assumptions(): array
    {
        return ['taux de test : 10 %'];
    }

    public function sources(): array
    {
        return ['fixture de test'];
    }
}
