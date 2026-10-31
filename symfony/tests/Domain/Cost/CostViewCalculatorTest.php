<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Cost\RafpContributionCalculator;
use App\Domain\Rule\CostView\ActuarialViewRule;
use App\Domain\Rule\CostView\AssumptionsActuarielles;
use App\Domain\Rule\CostView\BudgetViewRule;
use App\Domain\Rule\CostView\CostViewRule;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\CostView\PrivateEquivalentViewRule;
use App\Domain\Rule\CostView\TechnicalBalanceViewRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Surcouche « vue de coût » (C2/C3/C7/C9) : recompose l'AnnualCost en remplaçant
 * le SEUL terme retraite par celui de la vue sélectionnée, les trois autres termes
 * restant ceux d'AnnualCostCalculator. Isolé via des ports et règles factices
 * (aucun conteneur ni base). Valeurs de test : point 59.0734, IM 395, CAS civil
 * 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %, D = 2026-01-01.
 */
final class CostViewCalculatorTest extends TestCase
{
    private const DATE = '2026-01-01';

    public function testBudgetaireMatchesAnnualCostCalculatorTermByTerm(): void
    {
        // C3.1 : surcouche BUDGETAIRE == AnnualCostCalculator::compute (4 termes + total).
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();

        $reference = $this->annualCostCalculator()->compute($situation, $date);
        $view = $this->costViewCalculator()->compute($situation, $date, 'BUDGETAIRE');

        self::assertTrue($view->treatmentAndPrimes()->equals($reference->treatmentAndPrimes()));
        self::assertTrue($view->retirement()->equals($reference->retirement()));
        self::assertTrue($view->otherContributions()->equals($reference->otherContributions()));
        self::assertTrue($view->rafp()->equals($reference->rafp()));
        self::assertTrue($view->total()->equals($reference->total()));
    }

    public function testOnlyRetirementTermChangesForANonBudgetaireView(): void
    {
        // C2.1 + C7.1 : pour X_TEST_VIEW (TIB × 10 %), traitement+primes, autres et
        // RAFP sont ceux de BUDGETAIRE ; seul le terme retraite diffère.
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $calculator = $this->costViewCalculator();

        $budgetaire = $calculator->compute($situation, $date, 'BUDGETAIRE');
        $tested = $calculator->compute($situation, $date, 'X_TEST_VIEW');

        self::assertTrue($tested->treatmentAndPrimes()->equals($budgetaire->treatmentAndPrimes()));
        self::assertTrue($tested->otherContributions()->equals($budgetaire->otherContributions()));
        self::assertTrue($tested->rafp()->equals($budgetaire->rafp()));
        self::assertFalse($tested->retirement()->equals($budgetaire->retirement()));
    }

    public function testPluggableViewRetirementIsTibTimesItsRate(): void
    {
        // C7.1 : la 5ᵉ vue fixture est résolue et appliquée par la surcouche sans
        // modifier le moteur ; retraite = TIB × 10 % = 23333.993 × 0.10.
        $date = new \DateTimeImmutable(self::DATE);

        $cost = $this->costViewCalculator()->compute($this->situation(), $date, 'X_TEST_VIEW');

        self::assertSame('2333.399300', $cost->retirement()->toString());
    }

    public function testUnknownCostViewCodeThrows(): void
    {
        // C9.1 : costViewCode inconnu → UnknownRuleCodeException.
        $this->expectException(UnknownRuleCodeException::class);

        $this->costViewCalculator()->compute($this->situation(), new \DateTimeImmutable(self::DATE), 'INCONNU');
    }

    public function testEquivalentPriveReplacesOnlyRetirementAndRecomposesTotal(): void
    {
        // C4.1 + C2.1 : EQUIVALENT_PRIVE (TIB × 28 %) → retraite 6533.518040, total
        // recomposé ; traitement+primes, autres et RAFP identiques à BUDGETAIRE.
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $calculator = $this->costViewCalculator();

        $budgetaire = $calculator->compute($situation, $date, 'BUDGETAIRE');
        $view = $calculator->compute($situation, $date, 'EQUIVALENT_PRIVE');

        self::assertSame('6533.518040', $view->retirement()->toString());
        self::assertSame('37824.402653', $view->total()->toString());
        self::assertTrue($view->treatmentAndPrimes()->equals($budgetaire->treatmentAndPrimes()));
        self::assertTrue($view->otherContributions()->equals($budgetaire->otherContributions()));
        self::assertTrue($view->rafp()->equals($budgetaire->rafp()));
    }

    public function testEquilibreTechniqueReplacesOnlyRetirement(): void
    {
        // C5.1 + C2.1 : EQUILIBRE_TECHNIQUE CAS civil (TIB × 75 %) → retraite
        // 17500.494750 ; les trois autres termes restent ceux de BUDGETAIRE.
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $calculator = $this->costViewCalculator();

        $budgetaire = $calculator->compute($situation, $date, 'BUDGETAIRE');
        $view = $calculator->compute($situation, $date, 'EQUILIBRE_TECHNIQUE');

        self::assertSame('17500.494750', $view->retirement()->toString());
        self::assertTrue($view->treatmentAndPrimes()->equals($budgetaire->treatmentAndPrimes()));
        self::assertTrue($view->otherContributions()->equals($budgetaire->otherContributions()));
        self::assertTrue($view->rafp()->equals($budgetaire->rafp()));
        self::assertFalse($view->retirement()->equals($budgetaire->retirement()));
    }

    public function testActuarielleReplacesOnlyRetirement(): void
    {
        // C6.1 + C2.1 : ACTUARIELLE (TIB × 1.75 % × 12.5) → retraite 5104.310975 ;
        // les trois autres termes restent ceux de BUDGETAIRE.
        $date = new \DateTimeImmutable(self::DATE);
        $situation = $this->situation();
        $calculator = $this->costViewCalculator();

        $budgetaire = $calculator->compute($situation, $date, 'BUDGETAIRE');
        $view = $calculator->compute($situation, $date, 'ACTUARIELLE');

        self::assertSame('5104.310975', $view->retirement()->toString());
        self::assertTrue($view->treatmentAndPrimes()->equals($budgetaire->treatmentAndPrimes()));
        self::assertTrue($view->otherContributions()->equals($budgetaire->otherContributions()));
        self::assertTrue($view->rafp()->equals($budgetaire->rafp()));
        self::assertFalse($view->retirement()->equals($budgetaire->retirement()));
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
                'EQUILIBRE_TECHNIQUE' => new TechnicalBalanceViewRule($this->viewRateProvider()),
                'ACTUARIELLE' => new ActuarialViewRule(AssumptionsActuarielles::defaults()),
                'X_TEST_VIEW' => $this->tenPercentView(),
            ])),
        );
    }

    private function viewRateProvider(): DatedRateProvider
    {
        return new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                return match ($key) {
                    'taux_employeur_prive' => Rate::fromPercentage('28.00'),
                    'taux_equilibre_cas_civil' => Rate::fromPercentage('75.00'),
                    'taux_equilibre_cnracl' => Rate::fromPercentage('42.00'),
                    default => throw MissingDatedRateException::forKeyAndDate($key, $date),
                };
            }
        };
    }

    private function annualCostCalculator(?RetirementRuleResolver $retirementResolver = null): AnnualCostCalculator
    {
        return new AnnualCostCalculator(
            new IndexedSalaryCalculator($this->indexPointProvider()),
            $this->primeRateProvider('0.21'),
            $retirementResolver ?? $this->retirementResolver(),
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

    private function tenPercentView(): CostViewRule
    {
        return new class () implements CostViewRule {
            public function code(): string
            {
                return 'X_TEST_VIEW';
            }

            public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): Amount
            {
                return $tib->multipliedByRate(Rate::fromPercentage('10'));
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
        };
    }

    private function indexPointProvider(): DatedAmountProvider
    {
        return new class () implements DatedAmountProvider {
            public function amountAt(string $key, \DateTimeImmutable $date): Amount
            {
                if ($key !== 'point_indice') {
                    throw MissingDatedAmountException::forKeyAndDate($key, $date);
                }

                return Amount::fromString('59.0734');
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
                return new PrimeRateObservation(Rate::fromRatio($this->ratio), false);
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

            public function employerRate(\DateTimeImmutable $date): Rate
            {
                return Rate::fromPercentage($this->percentage);
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

            public function rate(\DateTimeImmutable $date): Rate
            {
                return Rate::fromPercentage($this->percentage);
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

            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                if ($key !== $this->expectedKey) {
                    throw new \LogicException(sprintf('Unexpected key "%s".', $key));
                }

                return Rate::fromPercentage($this->percentage);
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
