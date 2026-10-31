<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\CostView;

use App\Domain\Rule\CostView\BudgetViewRule;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Amount;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Vue budgétaire (C1/C3/C8) : retraite = TIB × employerRate(régime, date) via la
 * règle de régime existante, et métadonnées (définition/question/sources non vides,
 * assumptions vide). Isolé via un résolveur de retraite factice.
 */
final class BudgetViewRuleTest extends TestCase
{
    public function testCodeIsBudgetaire(): void
    {
        // C1 : la vue budgétaire est résolue par le code BUDGETAIRE.
        self::assertSame('BUDGETAIRE', $this->rule()->code());
    }

    public function testRetirementCostIsTibTimesRegimeEmployerRate(): void
    {
        // C3 : retraite = TIB × 82.28 % = 23333.993 × 0.8228.
        $tib = Amount::fromString('59.0734')->multipliedByIndex(395);

        $retirement = $this->rule()->retirementCost($tib, new \DateTimeImmutable('2026-01-01'), 'CAS_CIVIL');

        self::assertSame('19199.209440', $retirement->toString());
    }

    public function testAssumptionsAreEmpty(): void
    {
        // C8.1 : la vue budgétaire n'a aucune hypothèse.
        self::assertSame([], $this->rule()->assumptions());
    }

    public function testDefinitionQuestionAndSourcesAreNotEmpty(): void
    {
        // C8.1 : définition, question et sources renseignées (contenu exact, pour
        // garantir qu'aucun item de métadonnée ne peut être retiré silencieusement).
        $rule = $this->rule();

        self::assertSame(
            'Coût retraite = dépense employeur effective : TIB × taux de cotisation employeur du régime à la date simulée.',
            $rule->definition(),
        );
        self::assertSame(
            'Combien l\'employeur verse-t-il réellement au titre de la retraite cette année ?',
            $rule->question(),
        );
        self::assertSame(
            [
                'Taux de cotisation employeur CAS « Pensions civiles » (loi de finances).',
                'Taux de cotisation employeur CNRACL (décret annuel).',
            ],
            $rule->sources(),
        );
    }

    private function rule(): BudgetViewRule
    {
        return new BudgetViewRule(new RetirementRuleResolver($this->container([
            'CAS_CIVIL' => $this->retirementRule('CAS_CIVIL', '82.28'),
        ])));
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
