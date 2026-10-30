<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\OtherContributions;

use App\Domain\Rule\OtherContributions\FpeOtherContributionsRule;
use App\Domain\Rule\OtherContributions\FphOtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Résolution de la famille « autres cotisations » (r_autres) par versant, via les
 * règles réelles FPE/FPH taggées et découvertes par le service locator (C4).
 */
final class OtherContributionsRuleResolverTest extends KernelTestCase
{
    public function testResolvesFpeAndFphByVersant(): void
    {
        // C4.1 : chaque versant est résolu vers sa règle réelle taggée.
        $resolver = $this->resolver();

        self::assertInstanceOf(FpeOtherContributionsRule::class, $resolver->resolve('FPE'));
        self::assertInstanceOf(FphOtherContributionsRule::class, $resolver->resolve('FPH'));
    }

    public function testUnknownVersantThrowsDomainExceptionNamingFamilyAndCode(): void
    {
        // C4.2 : versant FPT sans implémentation -> exception de domaine (famille + code).
        $resolver = $this->resolver();

        try {
            $resolver->resolve('FPT');
            self::fail('Expected UnknownRuleCodeException for an unknown versant.');
        } catch (UnknownRuleCodeException $exception) {
            self::assertStringContainsString('autres cotisations', $exception->getMessage());
            self::assertStringContainsString('FPT', $exception->getMessage());
        }
    }

    private function resolver(): OtherContributionsRuleResolver
    {
        $resolver = self::getContainer()->get(OtherContributionsRuleResolver::class);
        self::assertInstanceOf(OtherContributionsRuleResolver::class, $resolver);

        return $resolver;
    }
}
