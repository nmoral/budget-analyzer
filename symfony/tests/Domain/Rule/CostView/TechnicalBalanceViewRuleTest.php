<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\CostView;

use App\Domain\Rule\CostView\TechnicalBalanceViewRule;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Vue équilibre technique (C5/C8) : retraite = TIB × rateAt('taux_equilibre_<régime>', date),
 * une clé par régime, dérivée du code de régime passé à retirementCost(). Isolé via
 * un DatedRateProvider factice qui ne connaît que les deux clés de régime attendues.
 */
final class TechnicalBalanceViewRuleTest extends TestCase
{
    public function testCodeIsEquilibreTechnique(): void
    {
        self::assertSame('EQUILIBRE_TECHNIQUE', $this->rule()->code());
    }

    public function testRetirementCostForCasCivilUsesItsKey(): void
    {
        // C5.1 : régime CAS_CIVIL → clé taux_equilibre_cas_civil → 75.00 %.
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);

        $retirement = $this->rule()->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');

        self::assertSame('17500.494750', $retirement->toString());
    }

    public function testRetirementCostForCnraclUsesItsKey(): void
    {
        // C5.2 : régime CNRACL → clé taux_equilibre_cnracl → 42.00 % (clé par régime).
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);

        $retirement = $this->rule()->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CNRACL');

        self::assertSame('9800.277060', $retirement->toString());
    }

    public function testUnknownRegimeHasNoEquilibriumKeyAndThrows(): void
    {
        // Mapping interne à la vue : un régime sans clé d'équilibre connue n'est pas
        // avalé silencieusement (MissingDatedRateException propagée par le provider).
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);

        $this->expectException(MissingDatedRateException::class);

        $this->rule()->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'X_INCONNU');
    }

    public function testMetadata(): void
    {
        // C8 : définition/question/sources non vides, assumptions non vide.
        $rule = $this->rule();

        self::assertNotSame('', $rule->definition());
        self::assertNotSame('', $rule->question());
        self::assertNotSame([], $rule->sources());
        self::assertSame(
            ['Taux d\'équilibre technique par régime (clés datées « taux_equilibre_cas_civil » / « taux_equilibre_cnracl »).'],
            $rule->assumptions(),
        );
    }

    private function rule(): TechnicalBalanceViewRule
    {
        return new TechnicalBalanceViewRule($this->rateProvider());
    }

    private function rateProvider(): DatedRateProvider
    {
        return new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                return match ($key) {
                    'taux_equilibre_cas_civil' => Rate::fromPercentage('75.00'),
                    'taux_equilibre_cnracl' => Rate::fromPercentage('42.00'),
                    default => throw MissingDatedRateException::forKeyAndDate($key, $date),
                };
            }
        };
    }
}
