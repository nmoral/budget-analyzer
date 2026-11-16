<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\ConcoursSession;

/**
 * #72 C4.1 — une `ConcoursSession` (FK Corps + Source + ParameterSet, session,
 * voie et effectifs entiers) est persistable et relisible via l'ORM.
 */
final class ConcoursSessionPersistenceTest extends IntegrationTestCase
{
    public function testConcoursSessionIsPersistedAndReread(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('menj-crpe-2025');
        $corps = $this->newCorps('PE');
        $concours = $this->newConcoursSession(
            2025,
            'externe',
            ['postes' => 8102, 'inscrits' => 43276, 'presents' => 18387, 'admis' => 7561],
            $corps,
            $source,
            $set,
        );

        $this->em->flush();
        $concoursId = $concours->id;
        self::assertNotNull($concoursId);

        // On repart d'un EntityManager vide : relecture réelle depuis la base.
        $this->em->clear();

        $reread = $this->em->find(ConcoursSession::class, $concoursId);
        self::assertInstanceOf(ConcoursSession::class, $reread);

        self::assertSame(2025, $reread->session);
        self::assertSame('externe', $reread->voie);
        self::assertSame(8102, $reread->postes);
        self::assertSame(43276, $reread->inscrits);
        self::assertSame(18387, $reread->presents);
        self::assertSame(7561, $reread->admis);

        // Rattachements (FK) correctement relus.
        self::assertSame('PE', $reread->body->code);
        self::assertSame('menj-crpe-2025', $reread->source->code);
        self::assertSame('commit-current', $reread->parameterSet->versionId);
    }
}
