<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\CostView;

use App\Domain\Rule\CostView\CostViewRule;
use App\Domain\Shared\Amount;
use App\Domain\Shared\Rate;
use App\Domain\Shared\RetirementValuation;

/**
 * Implémentation de test de la famille vue de coût (code ANNEE_1), prouvant la
 * découvrabilité par tag. Terme retraite et métadonnées déterministes et factices.
 */
final class AnnualCostViewRule implements CostViewRule
{
    public function code(): string
    {
        return 'ANNEE_1';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): RetirementValuation
    {
        return new RetirementValuation($tib->multipliedByRate(Rate::fromPercentage('0')), 'TIB·0', []);
    }

    public function definition(): string
    {
        return 'Vue de test.';
    }

    public function question(): string
    {
        return 'Question de test ?';
    }

    public function assumptions(): array
    {
        return [];
    }

    public function sources(): array
    {
        return ['test'];
    }
}
