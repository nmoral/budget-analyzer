<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\CorpsPayroll;

/**
 * C4.1 — une `CorpsPayroll` datée (FK Corps + Source + ParameterSet,
 * startDate + endDate) est persistable et relisible via l'ORM.
 */
final class CorpsPayrollPersistenceTest extends IntegrationTestCase
{
    public function testDatedCorpsPayrollIsPersistedAndReread(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('pap-2024-ens-scolaire-masse-1106');
        $corps = $this->newCorps('PE');
        $payroll = $this->newCorpsPayroll(
            '15590703468.00',
            '2024-01-01',
            '2024-12-31',
            $corps,
            $source,
            $set,
        );

        $this->em->flush();
        $payrollId = $payroll->id;
        self::assertNotNull($payrollId);

        // On repart d'un EntityManager vide : relecture réelle depuis la base.
        $this->em->clear();

        $reread = $this->em->find(CorpsPayroll::class, $payrollId);
        self::assertInstanceOf(CorpsPayroll::class, $reread);

        // Montant exposé en string (DECIMAL, jamais float) + unité.
        self::assertSame('15590703468.00', $reread->value);
        self::assertSame('EUR', $reread->unit);

        // Bornes de validité incluses [startDate, endDate].
        self::assertSame('2024-01-01', $reread->startDate->format('Y-m-d'));
        self::assertNotNull($reread->endDate);
        self::assertSame('2024-12-31', $reread->endDate->format('Y-m-d'));

        // Rattachements (FK) correctement relus.
        self::assertSame('PE', $reread->body->code);
        self::assertSame('pap-2024-ens-scolaire-masse-1106', $reread->source->code);
        self::assertSame('commit-current', $reread->parameterSet->versionId);
    }

    public function testOpenEndedCorpsPayrollHasNullEndDate(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('pap-2025-ens-scolaire-masse-1106');
        $corps = $this->newCorps('PE');
        $payroll = $this->newCorpsPayroll(
            '14994713610.00',
            '2025-01-01',
            null,
            $corps,
            $source,
            $set,
        );

        $this->em->flush();
        $payrollId = $payroll->id;
        self::assertNotNull($payrollId);
        $this->em->clear();

        $reread = $this->em->find(CorpsPayroll::class, $payrollId);
        self::assertInstanceOf(CorpsPayroll::class, $reread);
        self::assertSame('2025-01-01', $reread->startDate->format('Y-m-d'));
        self::assertNull($reread->endDate);
    }
}
