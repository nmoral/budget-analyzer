<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Cost\RafpContributionCalculator;
use App\Domain\Cost\ViewAwareStepCost;
use App\Domain\Rule\CostView\BudgetViewRule;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\CostView\PrivateEquivalentViewRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Stratégie de coût d'échelon « view-aware » (#66, C1.2) : délègue à CostViewCalculator
 * avec son code de vue mémorisé. Isolée via ports/règles factices (point 59.0734, IM
 * 395, CAS civil 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %, privé 28 %, D=2026-01-01).
 */
final class ViewAwareStepCostTest extends TestCase
{
    private const string DATE = '2026-01-01';

    public function testDelegatesToCostViewCalculatorWithItsViewCode(): void
    {
        // C1.2 : compute(situation, date) == CostViewCalculator::compute(..., viewCode).
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $viewCalculator = $this->costViewCalculator();

        $strategy = new ViewAwareStepCost($viewCalculator, 'EQUIVALENT_PRIVE');

        $expected = $viewCalculator->compute($situation, $date, 'EQUIVALENT_PRIVE');
        $actual = $strategy->compute($situation, $date);

        self::assertTrue($expected->treatmentAndPrimes()->equals($actual->treatmentAndPrimes()));
        self::assertTrue($expected->retirement()->equals($actual->retirement()));
        self::assertTrue($expected->otherContributions()->equals($actual->otherContributions()));
        self::assertTrue($expected->rafp()->equals($actual->rafp()));
        self::assertTrue($expected->total()->equals($actual->total()));
    }

    public function testDifferentViewCodesYieldDifferentRetirement(): void
    {
        // C1.2 : le code de vue mémorisé pilote la retraite ; budgétaire ≠ équivalent privé.
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $viewCalculator = $this->costViewCalculator();

        $budget = new ViewAwareStepCost($viewCalculator, 'BUDGETAIRE');
        $prive = new ViewAwareStepCost($viewCalculator, 'EQUIVALENT_PRIVE');

        $budgetCost = $budget->compute($situation, $date);
        $priveCost = $prive->compute($situation, $date);

        self::assertFalse($budgetCost->retirement()->equals($priveCost->retirement()));
        self::assertTrue($budgetCost->treatmentAndPrimes()->equals($priveCost->treatmentAndPrimes()));
        self::assertTrue($budgetCost->otherContributions()->equals($priveCost->otherContributions()));
        self::assertTrue($budgetCost->rafp()->equals($priveCost->rafp()));
    }

    private function situation(): AgentSituation
    {
        return new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');
    }

    private function costViewCalculator(): CostViewCalculator
    {
        $retirementResolver = $this->retirementResolver();

        return new CostViewCalculator(
            $this->annualCostCalculator($retirementResolver),
            new IndexedSalaryCalculator($this->indexPointProvider()),
            new CostViewRuleResolver($this->container([
                'BUDGETAIRE' => new BudgetViewRule($retirementResolver),
                'EQUIVALENT_PRIVE' => new PrivateEquivalentViewRule($this->viewRateProvider()),
            ])),
        );
    }

    private function viewRateProvider(): DatedRateProvider
    {
        return new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                return Trace::datedRate(match ($key) {
                    'taux_employeur_prive' => Rate::fromPercentage('28.00'),
                    default => throw MissingDatedRateException::forKeyAndDate($key, $date),
                });
            }
        };
    }

    private function annualCostCalculator(RetirementRuleResolver $retirementResolver): AnnualCostCalculator
    {
        return new AnnualCostCalculator(
            new IndexedSalaryCalculator($this->indexPointProvider()),
            $this->primeRateProvider('0.21'),
            $retirementResolver,
            new OtherContributionsRuleResolver($this->container([
                'FPE' => $this->otherRule('FPE', '10'),
            ])),
            new RafpContributionCalculator($this->ratePctProvider('taux_rafp', '5')),
        );
    }

    private function retirementResolver(): RetirementRuleResolver
    {
        return new RetirementRuleResolver($this->container([
            'CAS_CIVIL' => $this->retirementRule('CAS_CIVIL', '82.28'),
        ]));
    }

    private function indexPointProvider(): DatedAmountProvider
    {
        return new class () implements DatedAmountProvider {
            public function amountAt(string $key, \DateTimeImmutable $date): DatedAmount
            {
                if ($key !== 'point_indice') {
                    throw MissingDatedAmountException::forKeyAndDate($key, $date);
                }

                return Trace::datedAmount(Amount::fromString('59.0734'));
            }
        };
    }

    private function primeRateProvider(string $ratio): PrimeRateProvider
    {
        return new class ($ratio) implements PrimeRateProvider {
            public function __construct(private readonly string $ratio)
            {
            }

            public function primeRateAt(string $bodyCode, \DateTimeImmutable $date): PrimeRateObservation
            {
                return Trace::primeObservation(Rate::fromRatio($this->ratio), false);
            }
        };
    }

    private function retirementRule(string $code, string $percentage): RetirementRule
    {
        return new class ($code, $percentage) implements RetirementRule {
            public function __construct(
                private readonly string $codeValue,
                private readonly string $percentage,
            ) {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function employerRate(\DateTimeImmutable $date): DatedRate
            {
                return Trace::datedRate(Rate::fromPercentage($this->percentage));
            }
        };
    }

    private function otherRule(string $code, string $percentage): OtherContributionsRule
    {
        return new class ($code, $percentage) implements OtherContributionsRule {
            public function __construct(
                private readonly string $codeValue,
                private readonly string $percentage,
            ) {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function rate(\DateTimeImmutable $date): DatedRate
            {
                return Trace::datedRate(Rate::fromPercentage($this->percentage));
            }
        };
    }

    private function ratePctProvider(string $expectedKey, string $percentage): DatedRateProvider
    {
        return new class ($expectedKey, $percentage) implements DatedRateProvider {
            public function __construct(
                private readonly string $expectedKey,
                private readonly string $percentage,
            ) {
            }

            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                if ($key !== $this->expectedKey) {
                    throw new \LogicException(sprintf('Unexpected key "%s".', $key));
                }

                return Trace::datedRate(Rate::fromPercentage($this->percentage));
            }
        };
    }

    /**
     * @param array<string, object> $services
     */
    private function container(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /**
             * @param array<string, object> $services
             */
            public function __construct(private readonly array $services)
            {
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }
        };
    }
}
