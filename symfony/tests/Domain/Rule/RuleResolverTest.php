<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule;

use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\Funder\FunderRuleResolver;
use App\Domain\Rule\Indemnitary\IndemnitaryRuleResolver;
use App\Domain\Rule\Retirement\CasCivilRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use App\Tests\Fixtures\Rule\CostView\AnnualCostViewRule;
use App\Tests\Fixtures\Rule\CostView\EnfichableCostViewRule;
use App\Tests\Fixtures\Rule\Funder\EtatFunderRule;
use App\Tests\Fixtures\Rule\Indemnitary\IfseIndemnitaryRule;
use App\Tests\Fixtures\Rule\Retirement\EnfichableRetirementRule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Résolution des règles enfichables par famille, via des implémentations de TEST
 * taggées (aucune règle métier réelle n'est livrée en #12).
 */
final class RuleResolverTest extends KernelTestCase
{
    public function testResolvesTaggedRetirementImplementationByCode(): void
    {
        // C2.1 : famille retraite, code CAS_CIVIL présent -> règle réelle taggée (#13).
        $resolver = $this->retirementResolver();

        $rule = $resolver->resolve('CAS_CIVIL');

        self::assertInstanceOf(CasCivilRule::class, $rule);
        self::assertSame('CAS_CIVIL', $rule->code());
    }

    public function testPluggableImplementationIsResolvedByItsCode(): void
    {
        // C3.1 : une implémentation taggée X_TEST est résolue sans modifier le moteur.
        $resolver = $this->retirementResolver();

        $rule = $resolver->resolve('X_TEST');

        self::assertInstanceOf(EnfichableRetirementRule::class, $rule);
        self::assertSame('X_TEST', $rule->code());
    }

    public function testUnknownCodeThrowsDomainExceptionNamingFamilyAndCode(): void
    {
        // C4.1 : code sans implémentation -> exception de domaine (famille + code).
        $resolver = $this->retirementResolver();

        try {
            $resolver->resolve('CODE_INCONNU');
            self::fail('Expected UnknownRuleCodeException for an unknown code.');
        } catch (UnknownRuleCodeException $exception) {
            self::assertStringContainsString('retraite', $exception->getMessage());
            self::assertStringContainsString('CODE_INCONNU', $exception->getMessage());
        }
    }

    public function testEachFamilyResolverResolvesItsImplementation(): void
    {
        // C2 : les 4 familles disposent d'une interface + d'un résolveur dédié.
        $container = self::getContainer();

        $indemnitary = $container->get(IndemnitaryRuleResolver::class);
        self::assertInstanceOf(IndemnitaryRuleResolver::class, $indemnitary);
        self::assertInstanceOf(IfseIndemnitaryRule::class, $indemnitary->resolve('IFSE'));

        $funder = $container->get(FunderRuleResolver::class);
        self::assertInstanceOf(FunderRuleResolver::class, $funder);
        self::assertInstanceOf(EtatFunderRule::class, $funder->resolve('ETAT_P140'));

        $costView = $container->get(CostViewRuleResolver::class);
        self::assertInstanceOf(CostViewRuleResolver::class, $costView);
        self::assertInstanceOf(AnnualCostViewRule::class, $costView->resolve('ANNEE_1'));
    }

    public function testPluggableCostViewIsDiscoveredByTagAndResolvedByItsCode(): void
    {
        // C7.1 : ajouter une 5ᵉ vue taggée (X_TEST_VIEW) la rend résolvable par le
        // résolveur de la famille cost_view, sans modifier la surcouche ni le résolveur.
        $costView = self::getContainer()->get(CostViewRuleResolver::class);
        self::assertInstanceOf(CostViewRuleResolver::class, $costView);

        $rule = $costView->resolve('X_TEST_VIEW');

        self::assertInstanceOf(EnfichableCostViewRule::class, $rule);
        self::assertSame('X_TEST_VIEW', $rule->code());
    }

    private function retirementResolver(): RetirementRuleResolver
    {
        $resolver = self::getContainer()->get(RetirementRuleResolver::class);
        self::assertInstanceOf(RetirementRuleResolver::class, $resolver);

        return $resolver;
    }
}
