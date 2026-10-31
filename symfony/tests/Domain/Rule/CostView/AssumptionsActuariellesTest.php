<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\CostView;

use App\Domain\Rule\CostView\AssumptionsActuarielles;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * VO d'hypothèses actuarielles (C6) : défauts sourcés, surcharge possible et
 * connaissance des champs modifiés (isModified). Domaine pur, décimal-safe.
 */
final class AssumptionsActuariellesTest extends TestCase
{
    public function testDefaultsAreSourcedAndNotModified(): void
    {
        // C6.1 : défauts = taux d'annuité 1,75 %, facteur actuariel 12,5, non surchargés.
        $assumptions = AssumptionsActuarielles::defaults();

        self::assertSame('1.75', $assumptions->tauxAnnuite()->percentage());
        self::assertSame('12.5', $assumptions->facteurActuariel());
        self::assertFalse($assumptions->isModified(AssumptionsActuarielles::TAUX_ANNUITE));
        self::assertFalse($assumptions->isModified(AssumptionsActuarielles::FACTEUR_ACTUARIEL));
    }

    public function testOverridingFacteurMarksOnlyThatFieldModified(): void
    {
        // C6.2 : surcharge du facteur → facteur modifié, taux d'annuité « défaut ».
        $assumptions = AssumptionsActuarielles::defaults()->withFacteurActuariel('10.0');

        self::assertSame('10.0', $assumptions->facteurActuariel());
        self::assertSame('1.75', $assumptions->tauxAnnuite()->percentage());
        self::assertTrue($assumptions->isModified(AssumptionsActuarielles::FACTEUR_ACTUARIEL));
        self::assertFalse($assumptions->isModified(AssumptionsActuarielles::TAUX_ANNUITE));
    }

    public function testOverridingTauxAnnuiteMarksOnlyThatFieldModified(): void
    {
        $assumptions = AssumptionsActuarielles::defaults()->withTauxAnnuite(Rate::fromPercentage('2.00'));

        self::assertSame('2', $assumptions->tauxAnnuite()->percentage());
        self::assertSame('12.5', $assumptions->facteurActuariel());
        self::assertTrue($assumptions->isModified(AssumptionsActuarielles::TAUX_ANNUITE));
        self::assertFalse($assumptions->isModified(AssumptionsActuarielles::FACTEUR_ACTUARIEL));
    }

    public function testIsModifiedRejectsUnknownField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AssumptionsActuarielles::defaults()->isModified('champ_inconnu');
    }
}
