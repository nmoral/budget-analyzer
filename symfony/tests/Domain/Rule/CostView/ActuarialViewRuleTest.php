<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\CostView;

use App\Domain\Rule\CostView\ActuarialViewRule;
use App\Domain\Rule\CostView\AssumptionsActuarielles;
use App\Domain\Shared\Amount;
use PHPUnit\Framework\TestCase;

/**
 * Vue actuarielle (C6/C8) : retraite = (TIB × taux d'annuité) × facteur actuariel,
 * depuis le VO d'hypothèses injecté. Isolé (VO construit à la main, défaut/surchargé).
 */
final class ActuarialViewRuleTest extends TestCase
{
    public function testCodeIsActuarielle(): void
    {
        self::assertSame('ACTUARIELLE', $this->rule(AssumptionsActuarielles::defaults())->code());
    }

    public function testNominalRetirementUsesDefaultAssumptions(): void
    {
        // C6.1 : retraite = TIB × 1.75 % × 12.5 = 23333.993 × 0.0175 × 12.5.
        $retirement = $this->rule(AssumptionsActuarielles::defaults())
            ->retirementCost($this->tib(), new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');

        self::assertSame('5104.310975', $retirement->toString());
    }

    public function testOverriddenFacteurChangesTheResult(): void
    {
        // C6.2 : facteur 10.0 → retraite = TIB × 1.75 % × 10.0, ≠ cas nominal.
        $overridden = AssumptionsActuarielles::defaults()->withFacteurActuariel('10.0');

        $nominal = $this->rule(AssumptionsActuarielles::defaults())
            ->retirementCost($this->tib(), new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');
        $result = $this->rule($overridden)
            ->retirementCost($this->tib(), new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');

        self::assertSame('4083.448780', $result->toString());
        self::assertFalse($result->equals($nominal));
    }

    public function testMetadata(): void
    {
        // C8.1 : définition/question/sources non vides, assumptions liste les hypothèses.
        $rule = $this->rule(AssumptionsActuarielles::defaults());

        self::assertNotSame('', $rule->definition());
        self::assertNotSame('', $rule->question());
        self::assertNotSame([], $rule->sources());
        self::assertNotSame([], $rule->assumptions());
        self::assertCount(2, $rule->assumptions());
        self::assertCount(3, $rule->sources());
    }

    private function rule(AssumptionsActuarielles $assumptions): ActuarialViewRule
    {
        return new ActuarialViewRule($assumptions);
    }

    private function tib(): Amount
    {
        return Amount::fromString('59.0734')->multipliedByIndex(395);
    }
}
