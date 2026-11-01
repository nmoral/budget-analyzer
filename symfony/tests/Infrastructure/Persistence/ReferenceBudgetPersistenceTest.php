<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\ReferenceBudget;

/**
 * C4.1 — une `ReferenceBudget` datée (FK Funder + Source + ParameterSet,
 * startDate + endDate) est persistable et relisible via l'ORM.
 */
final class ReferenceBudgetPersistenceTest extends IntegrationTestCase
{
    public function testDatedReferenceBudgetIsPersistedAndReread(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('pap-2024-ens-scolaire');
        $funder = $this->newFunder('ETAT_P140');
        $budget = $this->newReferenceBudget(
            '26842958249.00',
            '2024-01-01',
            '2024-12-31',
            $funder,
            $source,
            $set,
        );

        $this->em->flush();
        $budgetId = $budget->id;
        self::assertNotNull($budgetId);

        // On repart d'un EntityManager vide : relecture réelle depuis la base.
        $this->em->clear();

        $reread = $this->em->find(ReferenceBudget::class, $budgetId);
        self::assertInstanceOf(ReferenceBudget::class, $reread);

        // Montant exposé en string (DECIMAL, jamais float) + unité.
        self::assertSame('26842958249.00', $reread->value);
        self::assertSame('EUR', $reread->unit);

        // Bornes de validité incluses [startDate, endDate].
        self::assertSame('2024-01-01', $reread->startDate->format('Y-m-d'));
        self::assertNotNull($reread->endDate);
        self::assertSame('2024-12-31', $reread->endDate->format('Y-m-d'));

        // Rattachements (FK) correctement relus.
        self::assertSame('ETAT_P140', $reread->funder->code);
        self::assertSame('pap-2024-ens-scolaire', $reread->source->code);
        self::assertSame('commit-current', $reread->parameterSet->versionId);
    }

    public function testOpenEndedReferenceBudgetHasNullEndDate(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('pap-2025-ens-scolaire');
        $funder = $this->newFunder('ETAT_P140');
        $budget = $this->newReferenceBudget(
            '27490907364.00',
            '2025-01-01',
            null,
            $funder,
            $source,
            $set,
        );

        $this->em->flush();
        $budgetId = $budget->id;
        self::assertNotNull($budgetId);
        $this->em->clear();

        $reread = $this->em->find(ReferenceBudget::class, $budgetId);
        self::assertInstanceOf(ReferenceBudget::class, $reread);
        self::assertSame('2025-01-01', $reread->startDate->format('Y-m-d'));
        self::assertNull($reread->endDate);
    }
}
