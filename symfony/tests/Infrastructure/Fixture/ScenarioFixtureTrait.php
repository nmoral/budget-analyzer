<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Fixture;

use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\DatedParameter;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\PrimeRate;
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
        $source->edition = '2026';
        $source->locator = 'p. 1';
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

    /**
     * Profil PE/FPE/IM395/CAS_CIVIL avec codes métier EXACTS (résolubles par le
     * moteur) et tous les paramètres datés nécessaires au calcul de coût, rattachés
     * au jeu `$set` : point d'indice, taux CAS civil, autres cotisations FPE, RAFP,
     * taux employeur privé (vue EQUIVALENT_PRIVE), et un taux de primes pour le corps.
     *
     * `$omitKeys` permet de volontairement ne PAS seeder certains paramètres datés (par
     * clé), et `$omitPrimeRate` d'omettre le taux de primes du corps : utile pour
     * reproduire un jeu sous-paramétré (vue valide mais non valorisable).
     *
     * @param list<string> $omitKeys
     */
    private function persistComputableProfile(
        EntityManagerInterface $em,
        ParameterSet $set,
        string $referenceCalendar = 'scolaire',
        string $corpsCode = 'PE',
        array $omitKeys = [],
        bool $omitPrimeRate = false,
    ): Profile {
        $corps = new Corps();
        $corps->code = $corpsCode;
        $corps->label = 'Professeur des écoles';
        $corps->versant = 'FPE';
        $corps->category = 'A';
        $em->persist($corps);

        $source = new Source();
        $source->code = 'SRC-grille-' . $corpsCode;
        $source->label = 'Source grille';
        $source->publisher = 'Éditeur';
        $source->edition = '2026';
        $source->locator = 'p. 1';
        $source->url = 'https://example.test/grille';
        $source->retrievedAt = new \DateTimeImmutable('2024-01-01');
        $source->fileHash = 'hash-grille-' . $corpsCode;
        $em->persist($source);

        $grille = new Grille();
        $grille->body = $corps;
        $grille->grade = 'classe normale';
        $grille->version = '2024';
        $grille->startDate = new \DateTimeImmutable('2020-01-01');
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
        $scheme->code = 'CAS_CIVIL';
        $scheme->label = 'CAS civil';
        $em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT';
        $funder->label = 'État';
        $em->persist($funder);

        $this->persistEngineParameters($em, $set, $corps, omitKeys: $omitKeys, omitPrimeRate: $omitPrimeRate);

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

    /**
     * Paramètres datés + taux de primes nécessaires au calcul, pour le corps `$corps`
     * et le jeu `$set` : point d'indice, taux CAS civil (`$casCivilPercentage`, variable
     * pour tester l'invalidation par version), autres cotisations FPE, RAFP, taux
     * employeur privé. Permet d'attacher un second jeu de paramètres au même corps.
     *
     * `$omitKeys` liste des clés datées à NE PAS seeder, `$omitPrimeRate` omet le taux
     * de primes : pour reproduire un jeu sous-paramétré.
     *
     * @param list<string> $omitKeys
     */
    private function persistEngineParameters(
        EntityManagerInterface $em,
        ParameterSet $set,
        Corps $corps,
        string $casCivilPercentage = '82.28',
        array $omitKeys = [],
        bool $omitPrimeRate = false,
    ): void {
        $source = new Source();
        $source->code = 'SRC-engine-' . $set->versionId;
        $source->label = 'Source calcul';
        $source->publisher = 'Éditeur';
        $source->edition = '2026';
        $source->locator = 'p. 1';
        $source->url = 'https://example.test/engine';
        $source->retrievedAt = new \DateTimeImmutable('2024-01-01');
        $source->fileHash = 'hash-engine-' . $set->versionId;
        $em->persist($source);

        $datedParameters = [
            'point_indice' => '59.0734',
            'taux_cas_civil' => $casCivilPercentage,
            'taux_autres_cotisations_fpe' => '16.07',
            'taux_rafp' => '5',
            'taux_employeur_prive' => '28',
        ];
        foreach ($datedParameters as $key => $value) {
            if (\in_array($key, $omitKeys, true)) {
                continue;
            }
            $this->persistDatedParameter($em, $set, $source, $key, $value);
        }

        if ($omitPrimeRate) {
            return;
        }

        $prime = new PrimeRate();
        $prime->body = $corps;
        $prime->value = '0.21';
        $prime->observationYear = 2026;
        $prime->fallback = false;
        $prime->source = $source;
        $prime->parameterSet = $set;
        $em->persist($prime);
    }

    private function persistDatedParameter(
        EntityManagerInterface $em,
        ParameterSet $set,
        Source $source,
        string $key,
        string $value,
    ): void {
        $param = new DatedParameter();
        $param->key = $key;
        $param->value = $value;
        $param->unit = 'UNIT';
        $param->startDate = new \DateTimeImmutable('2020-01-01');
        $param->endDate = null;
        $param->source = $source;
        $param->parameterSet = $set;
        $em->persist($param);
    }
}
