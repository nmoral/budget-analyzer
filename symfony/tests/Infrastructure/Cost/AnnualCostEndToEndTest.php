<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Shared\Rate;
use App\Infrastructure\Import\Importer;
use App\Tests\Infrastructure\Persistence\IntegrationTestCase;

/**
 * #49 C6 — preuve de déblocage de #14 : sur données réelles importées, le moteur de
 * coût calcule les quatre termes + total sans exception, r_autres et RAFP compris.
 *
 * `AgentSituation` est construit à la main (l'adapter depuis Profile/Echelon Doctrine
 * est #15, hors périmètre). Le calculateur est résolu du conteneur réel et lit les
 * taux datés via le port `DatedRateProvider` branché sur Postgres.
 */
final class AnnualCostEndToEndTest extends IntegrationTestCase
{
    public function testPeAgentCostUsesRealOtherContributionsAndRafp(): void
    {
        // C6.1 : agent PE (IM 395, FPE, CAS civil), D = 2026-06-01.
        $cost = $this->computeForImportedData(
            new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL'),
        );

        // Traitement+primes (p réel 0.21) et retraite (CAS civil 82.28 %) datés.
        self::assertSame('28234.131530', $cost->treatmentAndPrimes()->toString());
        self::assertSame('19199.209440', $cost->retirement()->toString());
        // r_autres FPE réel = 16.07 % appliqué à TIB·(1+p).
        self::assertTrue(
            $cost->otherContributions()->equals(
                $cost->treatmentAndPrimes()->multipliedByRate(Rate::fromPercentage('16.07')),
            ),
        );
        // RAFP réel = 5 % sur l'assiette plafonnée (0,2·TIB car p = 0.21 > 0.20).
        self::assertSame('233.339930', $cost->rafp()->toString());
        $this->assertTotalIsSumOfTerms($cost);
    }

    public function testIsgAgentCostUsesRealOtherContributionsAndRafp(): void
    {
        // C6.2 : agent ISG (IM 395, FPH, CNRACL), D = 2026-06-01.
        $cost = $this->computeForImportedData(
            new AgentSituation('ISG', 'FPH', 395, 'CNRACL'),
        );

        // Traitement+primes (p réel 0.33) et retraite (CNRACL 37.65 %) datés.
        self::assertSame('31034.210690', $cost->treatmentAndPrimes()->toString());
        self::assertSame('8785.248365', $cost->retirement()->toString());
        // r_autres FPH réel = 17.13 % appliqué à TIB·(1+p).
        self::assertTrue(
            $cost->otherContributions()->equals(
                $cost->treatmentAndPrimes()->multipliedByRate(Rate::fromPercentage('17.13')),
            ),
        );
        // RAFP réel = 5 % sur l'assiette plafonnée (0,2·TIB car p = 0.33 > 0.20).
        self::assertSame('233.339930', $cost->rafp()->toString());
        $this->assertTotalIsSumOfTerms($cost);
    }

    private function computeForImportedData(AgentSituation $situation): AnnualCost
    {
        $this->importer()->import('c6-' . substr(md5($situation->bodyCode()), 0, 7), $this->dataDir());

        return $this->calculator()->compute($situation, new \DateTimeImmutable('2026-06-01'));
    }

    private function assertTotalIsSumOfTerms(AnnualCost $cost): void
    {
        $sum = $cost->treatmentAndPrimes()
            ->plus($cost->retirement())
            ->plus($cost->otherContributions())
            ->plus($cost->rafp());

        self::assertTrue($cost->total()->equals($sum));
    }

    private function importer(): Importer
    {
        $importer = self::getContainer()->get(Importer::class);
        self::assertInstanceOf(Importer::class, $importer);

        return $importer;
    }

    private function calculator(): AnnualCostCalculator
    {
        $calculator = self::getContainer()->get(AnnualCostCalculator::class);
        self::assertInstanceOf(AnnualCostCalculator::class, $calculator);

        return $calculator;
    }

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }
}
