<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\CareerStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Value object de domaine PUR `CareerStep` : situation d'un agent + durée en mois
 * de l'échelon ; la durée doit être strictement positive (C4).
 */
final class CareerStepTest extends TestCase
{
    public function testExposesSituationAndDuration(): void
    {
        $situation = new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');
        $step = new CareerStep($situation, 12);

        self::assertSame($situation, $step->situation());
        self::assertSame(12, $step->durationMonths());
    }

    public function testZeroDurationThrows(): void
    {
        // C4.4 : CareerStep(situation, 0) → erreur (durée > 0).
        $situation = new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('strictement positive');

        new CareerStep($situation, 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'nulle (C4.4)' => [0];
        yield 'négative' => [-1];
    }

    #[DataProvider('invalidDurations')]
    public function testNonPositiveDurationThrows(int $durationMonths): void
    {
        $situation = new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');

        $this->expectException(\InvalidArgumentException::class);

        new CareerStep($situation, $durationMonths);
    }

    public function testStrictlyPositiveDurationIsAccepted(): void
    {
        // Borne : 1 mois est accepté (durée > 0, pas >= certaine valeur).
        $situation = new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');

        self::assertSame(1, (new CareerStep($situation, 1))->durationMonths());
    }
}
