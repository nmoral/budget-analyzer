<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Rule\Retirement\CasCivilRule;
use App\Domain\Rule\Retirement\CnraclRule;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Rate;
use App\Infrastructure\Entity\ParameterSet;
use App\Tests\Fixtures\Rule\Retirement\EnfichableRetirementRule;

/**
 * C4/C5 — règles de retraite réelles résolues par le résolveur du profil (#12),
 * taux lu depuis les paramètres datés (Postgres réel), aucun taux en dur.
 */
final class RetirementRuleResolutionTest extends IntegrationTestCase
{
    public function testCasCivilEmployerRate2026(): void
    {
        // C4.1 : taux_cas_civil = 82.28 depuis 2026.
        $this->seedParameter('taux_cas_civil', '82.28', '2026-01-01', null);

        $rate = $this->resolve('CAS_CIVIL')->employerRate(new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($rate->equals(Rate::fromPercentage('82.28')));
    }

    public function testCnraclEmployerRateFollowsDatedValue(): void
    {
        // C4.2 / C4.3 : CNRACL 37,65 % en 2026, 40,65 % en 2027.
        $set = $this->currentSet();
        $source = $this->newSource('retraites-etat-2026');
        $this->newDatedParameter('taux_cnracl', '37.65', '2026-01-01', '2026-12-31', $source, $set);
        $this->newDatedParameter('taux_cnracl', '40.65', '2027-01-01', null, $source, $set);
        $this->em->flush();

        $cnracl = $this->resolve('CNRACL');

        self::assertTrue(
            $cnracl->employerRate(new \DateTimeImmutable('2026-06-01'))->equals(Rate::fromPercentage('37.65')),
        );
        self::assertTrue(
            $cnracl->employerRate(new \DateTimeImmutable('2027-06-01'))->equals(Rate::fromPercentage('40.65')),
        );
    }

    public function testRealRuleResolvedWhileFixtureStillPluggable(): void
    {
        // C4.4 : CAS_CIVIL -> vraie règle de src/ ; X_TEST -> fixture ; aucune collision.
        self::assertInstanceOf(CasCivilRule::class, $this->resolve('CAS_CIVIL'));
        self::assertInstanceOf(CnraclRule::class, $this->resolve('CNRACL'));
        self::assertInstanceOf(EnfichableRetirementRule::class, $this->resolve('X_TEST'));
    }

    public function testEmployerRateFollowsTheSeededParameter(): void
    {
        // C5.1 : pas de taux en dur — la valeur du paramètre pilote le résultat.
        $this->seedParameter('taux_cas_civil', '90.00', '2026-01-01', null);

        $rate = $this->resolve('CAS_CIVIL')->employerRate(new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($rate->equals(Rate::fromPercentage('90.00')));
    }

    private function seedParameter(string $key, string $value, string $startDate, ?string $endDate): void
    {
        $set = $this->currentSet();
        $source = $this->newSource('retraites-etat-2026');
        $this->newDatedParameter($key, $value, $startDate, $endDate, $source, $set);
        $this->em->flush();
    }

    private function resolve(string $code): RetirementRule
    {
        $resolver = self::getContainer()->get(RetirementRuleResolver::class);
        self::assertInstanceOf(RetirementRuleResolver::class, $resolver);

        return $resolver->resolve($code);
    }

    private function currentSet(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->em->flush();

        return $set;
    }
}
