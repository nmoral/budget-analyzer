<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Fixture;

use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\RetirementScheme;
use App\Infrastructure\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Fabriques de fixtures partagées par les tests de l'API scénarios (#19).
 *
 * Opèrent sur un `EntityManagerInterface` fourni, afin d'être réutilisables aussi
 * bien par un `WebTestCase` (API HTTP) que par un test d'intégration persistance.
 */
trait ScenarioFixtureTrait
{
    private function createScenarioSchema(EntityManagerInterface $em): void
    {
        $this->dropScenarioSchema($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($em))->createSchema($metadata);
    }

    private function dropScenarioSchema(EntityManagerInterface $em): void
    {
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($em))->dropSchema($metadata);
        $em->getConnection()->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
    }

    private function persistParameterSet(
        EntityManagerInterface $em,
        string $versionId,
        string $importedAt = '2026-01-01',
    ): ParameterSet {
        $set = new ParameterSet();
        $set->versionId = $versionId;
        $set->importedAt = new \DateTimeImmutable($importedAt);
        $em->persist($set);

        return $set;
    }

    /**
     * Profil complet (corps, grille, échelon IM395, régime CAS_CIVIL, financeur),
     * calendrier scolaire par défaut. La grille est rattachée au jeu de paramètres.
     */
    private function persistProfile(
        EntityManagerInterface $em,
        ParameterSet $set,
        string $referenceCalendar = 'scolaire',
    ): Profile {
        $corps = new Corps();
        $corps->code = 'PE-' . uniqid();
        $corps->label = 'Professeur des écoles';
        $corps->versant = 'FPE';
        $corps->category = 'A';
        $em->persist($corps);

        $source = new Source();
        $source->code = 'SRC-' . uniqid();
        $source->label = 'Source grille';
        $source->publisher = 'Éditeur';
        $source->url = 'https://example.test/grille';
        $source->retrievedAt = new \DateTimeImmutable('2024-01-01');
        $source->fileHash = 'hash-grille';
        $em->persist($source);

        $grille = new Grille();
        $grille->body = $corps;
        $grille->grade = 'classe normale';
        $grille->version = '2024';
        $grille->startDate = new \DateTimeImmutable('2024-01-01');
        $grille->endDate = null;
        $grille->source = $source;
        $grille->parameterSet = $set;
        $em->persist($grille);

        $echelon = new Echelon();
        $echelon->grille = $grille;
        $echelon->rank = 1;
        $echelon->grossIndex = 390;
        $echelon->increasedIndex = 395;
        $echelon->durationMonths = 12;
        $em->persist($echelon);

        $scheme = new RetirementScheme();
        $scheme->code = 'CAS_CIVIL-' . uniqid();
        $scheme->label = 'CAS civil';
        $em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT-' . uniqid();
        $funder->label = 'État';
        $em->persist($funder);

        $profile = new Profile();
        $profile->body = $corps;
        $profile->grade = 'classe normale';
        $profile->entryEchelon = $echelon;
        $profile->retirementScheme = $scheme;
        $profile->indemnitaryScheme = null;
        $profile->funder = $funder;
        $profile->status = 'titulaire';
        $profile->referenceCalendar = $referenceCalendar;
        $em->persist($profile);

        return $profile;
    }
}
