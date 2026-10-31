<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\CostView;

use App\Domain\Rule\CostView\PrivateEquivalentViewRule;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;

/**
 * Vue équivalent privé (C4/C8) : retraite = TIB × rateAt('taux_employeur_prive', date).
 * Isolé via un DatedRateProvider factice exigeant la clé attendue.
 */
final class PrivateEquivalentViewRuleTest extends TestCase
{
    public function testCodeIsEquivalentPrive(): void
    {
        self::assertSame('EQUIVALENT_PRIVE', $this->rule($this->rateProvider())->code());
    }

    public function testRetirementCostIsTibTimesPrivateEmployerRate(): void
    {
        // C4.1 : retraite = TIB × 28.00 % = 23333.993 × 0.28.
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);

        $retirement = $this->rule($this->rateProvider())
            ->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');

        self::assertSame('6533.518040', $retirement->amount()->toString());
        self::assertSame('TIB·taux_employeur_prive', $retirement->formula());
    }

    public function testRetirementCostIgnoresRegimeCodeAndReadsThePrivateKey(): void
    {
        // C4 : la clé est fixe (taux_employeur_prive), indépendante du régime.
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);
        $rule = $this->rule($this->rateProvider());

        $forCasCivil = $rule->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');
        $forCnracl = $rule->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CNRACL');

        self::assertTrue($forCasCivil->amount()->equals($forCnracl->amount()));
    }

    public function testMetadata(): void
    {
        // C8 : définition/question/sources non vides, assumptions non vide.
        $rule = $this->rule($this->rateProvider());

        self::assertNotSame('', $rule->definition());
        self::assertNotSame('', $rule->question());
        self::assertNotSame([], $rule->sources());
        self::assertSame(
            ['Taux de cotisation vieillesse employeur du secteur privé (clé datée « taux_employeur_prive »).'],
            $rule->assumptions(),
        );
    }

    private function rule(DatedRateProvider $rates): PrivateEquivalentViewRule
    {
        return new PrivateEquivalentViewRule($rates);
    }

    private function rateProvider(): DatedRateProvider
    {
        return new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                if ($key !== 'taux_employeur_prive') {
                    throw MissingDatedRateException::forKeyAndDate($key, $date);
                }

                return Trace::datedRate(Rate::fromPercentage('28.00'));
            }
        };
    }
}
