<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Profile;

use App\Domain\Profile\Profile as DomainProfile;
use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Profile as ProfileEntity;
use App\Infrastructure\Entity\RetirementScheme;
use App\Infrastructure\Profile\DomainProfileFactory;
use App\Tests\Infrastructure\Persistence\IntegrationTestCase;

/**
 * C5.2 — l'adapter d'infrastructure construit un `Profile` de domaine PUR,
 * équivalent (codes identiques) à l'entité Doctrine, sans fuite Doctrine.
 */
final class DomainProfileFactoryTest extends IntegrationTestCase
{
    public function testBuildsEquivalentPureProfileFromDoctrineEntity(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $source = $this->newSource('decret-2017-789');
        $corps = $this->newCorps('PE');
        $grille = $this->newGrille($corps, 'classe_normale', '2024', '2024-01-01', null, $source, $set);
        $echelon = $this->newEchelon($grille, 1, 444, 395);

        $scheme = new RetirementScheme();
        $scheme->code = 'CAS_CIVIL';
        $scheme->label = 'CAS civil';
        $this->em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT_P140';
        $funder->label = 'État — programme 140';
        $this->em->persist($funder);

        $entity = new ProfileEntity();
        $entity->body = $corps;
        $entity->grade = 'classe_normale';
        $entity->entryEchelon = $echelon;
        $entity->retirementScheme = $scheme;
        $entity->indemnitaryScheme = 'IFSE';
        $entity->funder = $funder;
        $entity->status = 'titulaire';
        $entity->referenceCalendar = 'scolaire';
        $this->em->persist($entity);

        $this->em->flush();
        $entityId = $entity->id;
        self::assertNotNull($entityId);

        // On repart d'un EntityManager vide : relecture réelle depuis la base.
        $this->em->clear();

        $reread = $this->em->find(ProfileEntity::class, $entityId);
        self::assertInstanceOf(ProfileEntity::class, $reread);

        $domainProfile = (new DomainProfileFactory())->fromEntity($reread);

        // Objet de domaine pur (App\Domain, aucune fuite Doctrine — frontière
        // garantie par Deptrac), distinct de l'entité de persistance.
        self::assertInstanceOf(DomainProfile::class, $domainProfile);

        // Codes identiques : retraite=RetirementScheme.code, indemnitaire=brut,
        // financeur=Funder.code.
        self::assertSame('CAS_CIVIL', $domainProfile->retirementCode());
        self::assertSame('IFSE', $domainProfile->indemnitaryCode());
        self::assertSame('ETAT_P140', $domainProfile->funderCode());
    }
}
