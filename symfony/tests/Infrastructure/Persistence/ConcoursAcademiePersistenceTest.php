<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\Academie;
use App\Infrastructure\Entity\ConcoursAcademie;

/**
 * #80 C4.1 — une `ConcoursAcademie` (FK Corps + Academie + Source + ParameterSet,
 * session, voie et effectifs entiers) et son `Academie` (code officiel, libellé,
 * statut, source) sont persistables et relisibles via l'ORM.
 */
final class ConcoursAcademiePersistenceTest extends IntegrationTestCase
{
    public function testConcoursAcademieIsPersistedAndReread(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('menj-crpe-2025-academies');
        $referentialSource = $this->newSource('data-education-annuaire-academies');
        $corps = $this->newCorps('PE');
        $academie = $this->newAcademie('32', 'Guadeloupe', 'drom', $referentialSource);
        $concours = $this->newConcoursAcademie(
            2025,
            'externe',
            ['postes' => 26, 'inscrits' => 442, 'presents' => 165, 'admis' => 26],
            $corps,
            $academie,
            $source,
            $set,
        );

        $this->em->flush();
        $concoursId = $concours->id;
        self::assertNotNull($concoursId);

        $this->em->clear();

        $reread = $this->em->find(ConcoursAcademie::class, $concoursId);
        self::assertInstanceOf(ConcoursAcademie::class, $reread);

        self::assertSame(2025, $reread->session);
        self::assertSame('externe', $reread->voie);
        self::assertSame(26, $reread->postes);
        self::assertSame(442, $reread->inscrits);
        self::assertSame(165, $reread->presents);
        self::assertSame(26, $reread->admis);

        self::assertSame('PE', $reread->body->code);
        self::assertSame('menj-crpe-2025-academies', $reread->source->code);
        self::assertSame('commit-current', $reread->parameterSet->versionId);

        self::assertInstanceOf(Academie::class, $reread->academie);
        self::assertSame('32', $reread->academie->code);
        self::assertSame('Guadeloupe', $reread->academie->label);
        self::assertSame('drom', $reread->academie->statut);
        self::assertSame('data-education-annuaire-academies', $reread->academie->source->code);
    }
}
