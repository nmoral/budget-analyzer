<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCostCalculator;

/**
 * C5 — coût annuel assemblé de bout en bout via le câblage du conteneur et des
 * paramètres datés réels (Postgres) : TIB (point d'indice), p (taux de primes),
 * r_retraite (règle du régime), r_autres (règle du versant) et RAFP (taux_rafp).
 * Taux FICTIFS de test : r_autres FPE 10 %, RAFP 5 % (données réelles absentes, #49).
 */
final class AnnualCostCalculatorIntegrationTest extends IntegrationTestCase
{
    public function testComputesFullAnnualCostEndToEnd(): void
    {
        // C5.1 : IM 395, CAS civil 82.28 % à 2026, p 0.21, r_autres 10 %, RAFP 5 %.
        $this->seed();

        $cost = $this->calculator()->compute(
            new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL'),
            new \DateTimeImmutable('2026-06-01'),
        );

        self::assertSame('28234.131530', $cost->treatmentAndPrimes()->toString());
        self::assertSame('19199.209440', $cost->retirement()->toString());
        self::assertSame('2823.413153', $cost->otherContributions()->toString());
        self::assertSame('233.339930', $cost->rafp()->toString());
        self::assertSame('50490.094053', $cost->total()->toString());
    }

    public function testRetirementTermFollowsTheDatedRate(): void
    {
        // C5.3 : même agent, D = 2025-06-01 → CAS civil 78.28 % à cette date.
        $this->seed();

        $cost = $this->calculator()->compute(
            new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL'),
            new \DateTimeImmutable('2025-06-01'),
        );

        self::assertSame('18265.849720', $cost->retirement()->toString());
        self::assertSame('49556.734333', $cost->total()->toString());
    }

    private function seed(): void
    {
        $set = $this->newParameterSet('commit-2026', '2026-01-15 10:00:00');
        $source = $this->newSource('seed-14');
        $this->newDatedParameter('point_indice', '59.0734', '2023-07-01', null, $source, $set);
        $this->newDatedParameter('taux_cas_civil', '78.28', '2025-01-01', '2025-12-31', $source, $set);
        $this->newDatedParameter('taux_cas_civil', '82.28', '2026-01-01', null, $source, $set);
        $this->newDatedParameter('taux_autres_cotisations_fpe', '10', '2023-07-01', null, $source, $set);
        $this->newDatedParameter('taux_rafp', '5', '2023-07-01', null, $source, $set);
        $this->newPrimeRate($this->newCorps('PE'), '0.21', 2023, false, $source, $set);
        $this->em->flush();
    }

    private function calculator(): AnnualCostCalculator
    {
        $calculator = self::getContainer()->get(AnnualCostCalculator::class);
        self::assertInstanceOf(AnnualCostCalculator::class, $calculator);

        return $calculator;
    }
}
