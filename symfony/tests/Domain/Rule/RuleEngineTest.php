<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule;

use App\Domain\Profile\Profile;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\Funder\FunderRuleResolver;
use App\Domain\Rule\Indemnitary\IndemnitaryRuleResolver;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Rule\Rule;
use App\Domain\Rule\RuleEngine;
use App\Tests\Fixtures\Rule\CostView\AnnualCostViewRule;
use App\Tests\Fixtures\Rule\Funder\EtatFunderRule;
use App\Tests\Fixtures\Rule\Indemnitary\IfseIndemnitaryRule;
use App\Tests\Fixtures\Rule\Retirement\EnfichableRetirementRule;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Résolution du moteur depuis un profil de domaine PUR + le code de vue de coût
 * passé en entrée. Test unitaire sans I/O ni kernel : les résolveurs sont câblés
 * sur un service locator en mémoire (port PSR-11).
 */
final class RuleEngineTest extends TestCase
{
    public function testResolvesTheFourImplementationsFromPureProfileAndCostViewCode(): void
    {
        // C5.1 : Profile pur (retraite X_TEST, indemnitaire IFSE, financeur
        // ETAT_P140) + vue ANNEE_1 -> les 4 implémentations correspondantes. On
        // utilise l'implémentation d'enfichage X_TEST (sans dépendance) : le moteur
        // ne connaît que l'identité de sélection, pas la famille de règle réelle.
        $engine = $this->engineWith(
            new EnfichableRetirementRule(),
            new IfseIndemnitaryRule(),
            new EtatFunderRule(),
            new AnnualCostViewRule(),
        );

        $profile = new Profile('X_TEST', 'IFSE', 'ETAT_P140');

        $resolved = $engine->resolve($profile, 'ANNEE_1');

        self::assertInstanceOf(EnfichableRetirementRule::class, $resolved->retirement());
        self::assertInstanceOf(IfseIndemnitaryRule::class, $resolved->indemnitary());
        self::assertInstanceOf(EtatFunderRule::class, $resolved->funder());
        self::assertInstanceOf(AnnualCostViewRule::class, $resolved->costView());
    }

    private function engineWith(Rule ...$rules): RuleEngine
    {
        $indexed = [];
        foreach ($rules as $rule) {
            $indexed[$rule->code()] = $rule;
        }

        $locator = $this->locator($indexed);

        return new RuleEngine(
            new RetirementRuleResolver($locator),
            new IndemnitaryRuleResolver($locator),
            new FunderRuleResolver($locator),
            new CostViewRuleResolver($locator),
        );
    }

    /**
     * @param array<string, Rule> $rules
     */
    private function locator(array $rules): ContainerInterface
    {
        return new class ($rules) implements ContainerInterface {
            /**
             * @param array<string, Rule> $rules
             */
            public function __construct(private readonly array $rules)
            {
            }

            public function get(string $id): mixed
            {
                return $this->rules[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->rules[$id]);
            }
        };
    }
}
