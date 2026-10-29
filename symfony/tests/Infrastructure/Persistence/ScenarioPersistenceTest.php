<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\RetirementScheme;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;

/**
 * C5 — un scénario d'une seule ligne est persistable et relisible via l'ORM.
 */
final class ScenarioPersistenceTest extends IntegrationTestCase
{
    public function testSingleLineScenarioIsPersistedAndReread(): void
    {
        // C5.1 : chaîne Source -> Corps -> Grille -> Échelon, puis Régime, Financeur,
        // Profil, puis Scénario + 1 Ligne. Relecture après clear() (pas l'objet en mémoire).
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('decret-2017-789');
        $corps = $this->newCorps('PE');
        $grille = $this->newGrille($corps, 'classe_normale', '2024', '2024-01-01', null, $source, $set);
        $echelon = $this->newEchelon($grille, 1, 444, 395);

        $scheme = new RetirementScheme();
        $scheme->code = 'CNRACL';
        $scheme->label = 'CNRACL';
        $this->em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT_P140';
        $funder->label = 'État — programme 140';
        $funder->mission = 'Enseignement scolaire';
        $funder->program = '140';
        $funder->action = '01';
        $this->em->persist($funder);

        $profile = new Profile();
        $profile->body = $corps;
        $profile->grade = 'classe_normale';
        $profile->entryEchelon = $echelon;
        $profile->retirementScheme = $scheme;
        $profile->indemnitaryScheme = 'IFSE';
        $profile->funder = $funder;
        $profile->status = 'titulaire';
        $profile->referenceCalendar = 'scolaire';
        $this->em->persist($profile);

        $scenario = new Scenario();
        $scenario->title = 'Recrutement PE 2026';
        $scenario->provenance = 'test';
        $scenario->announcedAmount = '1000000.00';
        $scenario->parameterSet = $set;
        $this->em->persist($scenario);

        $line = new ScenarioLine();
        $line->scenario = $scenario;
        $line->profile = $profile;
        $line->volume = 1;
        $line->recruitmentDate = new \DateTimeImmutable('2026-09-01');
        $line->spreadYears = 1;
        $this->em->persist($line);

        $this->em->flush();
        $lineId = $line->id;
        self::assertNotNull($lineId);

        // On repart d'un EntityManager vide : relecture réelle depuis la base.
        $this->em->clear();

        $reread = $this->em->find(ScenarioLine::class, $lineId);
        self::assertInstanceOf(ScenarioLine::class, $reread);

        // Champs propres de la ligne.
        self::assertSame(1, $reread->volume);
        self::assertSame('2026-09-01', $reread->recruitmentDate->format('Y-m-d'));
        self::assertSame(1, $reread->spreadYears);

        // Rattachement au scénario et tous ses champs.
        self::assertSame('Recrutement PE 2026', $reread->scenario->title);
        self::assertSame('test', $reread->scenario->provenance);
        self::assertSame(1000000.00, (float) $reread->scenario->announcedAmount);
        self::assertSame('commit-current', $reread->scenario->parameterSet->versionId);

        // Rattachement au profil et tous ses champs.
        $rereadProfile = $reread->profile;
        self::assertSame('PE', $rereadProfile->body->code);
        self::assertSame('classe_normale', $rereadProfile->grade);
        self::assertSame(1, $rereadProfile->entryEchelon->rank);
        self::assertSame(444, $rereadProfile->entryEchelon->grossIndex);
        self::assertSame('classe_normale', $rereadProfile->entryEchelon->grille->grade);
        self::assertSame('CNRACL', $rereadProfile->retirementScheme->code);
        self::assertSame('IFSE', $rereadProfile->indemnitaryScheme);
        self::assertSame('ETAT_P140', $rereadProfile->funder->code);
        self::assertSame('140', $rereadProfile->funder->program);
        self::assertSame('titulaire', $rereadProfile->status);
        self::assertSame('scolaire', $rereadProfile->referenceCalendar);
    }
}
