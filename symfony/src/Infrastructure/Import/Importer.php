<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Infrastructure\Entity\DatedParameter;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestration de l'import du dépôt de données vers Postgres (J1).
 *
 * Tout se joue dans une seule transaction (atomicité, C2) : validation Frictionless
 * de tous les CSV du périmètre d'import (C3), vérification des empreintes des bruts
 * (C4), puis — pour ce jalon — création du `ParameterSet` versionné (C1), chargement
 * des valeurs datées (`DatedParameter`) des 4 fichiers `parametre_date` et peuplement
 * du référentiel `Source` pour les slugs qu'elles référencent.
 *
 * Grille / Echelon / PrimeRate / Corps relèvent de J2 ; l'idempotence, de J3.
 */
final class Importer
{
    /**
     * Mapping fichier → schéma Frictionless du périmètre d'import.
     *
     * Aligné sur `FILE_TO_SCHEMA` (Python), hors `couts_pap` qui n'est pas importé
     * (aucune entité cible, cf. hors périmètre #11).
     *
     * @var array<string, string>
     */
    public const FILE_TO_SCHEMA = [
        'point_indice' => 'parametre_date',
        'taux_cas_civil' => 'parametre_date',
        'taux_cas_militaire' => 'parametre_date',
        'taux_cnracl' => 'parametre_date',
        'echelon_pe' => 'echelon',
        'echelon_isg' => 'echelon',
        'grille_pe' => 'grille',
        'grille_isg' => 'grille',
        'taux_primes' => 'taux_primes',
    ];

    /**
     * Fichiers chargés en `DatedParameter` sur ce jalon (schéma `parametre_date`).
     *
     * @var list<string>
     */
    private const DATED_PARAMETER_FILES = [
        'point_indice',
        'taux_cas_civil',
        'taux_cas_militaire',
        'taux_cnracl',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsvReader $csvReader,
        private readonly FrictionlessSchemaValidator $validator,
        private readonly ManifestVerifier $manifestVerifier,
        private readonly SourceReferential $referential,
    ) {
    }

    /**
     * @throws ImportException tout échec (schéma, empreinte, source inconnue) → rollback total
     */
    public function import(string $commit, string $dataDir): void
    {
        $this->em->wrapInTransaction(function () use ($commit, $dataDir): void {
            $parsed = $this->validateAndVerify($dataDir);
            $referential = $this->referential->load($dataDir);

            $set = new ParameterSet();
            $set->versionId = $commit;
            $set->importedAt = new \DateTimeImmutable();
            $this->em->persist($set);

            /** @var array<string, Source> $sources */
            $sources = [];
            foreach (self::DATED_PARAMETER_FILES as $file) {
                foreach ($parsed[$file]['rows'] as $row) {
                    $slug = $row['source'];
                    $source = $sources[$slug] ??= $this->createSource($slug, $referential);

                    $parameter = new DatedParameter();
                    $parameter->key = $row['key'];
                    $parameter->value = $row['value'];
                    $parameter->unit = $row['unit'];
                    $parameter->startDate = new \DateTimeImmutable($row['start_date']);
                    $parameter->endDate = $row['end_date'] === '' ? null : new \DateTimeImmutable($row['end_date']);
                    $parameter->source = $source;
                    $parameter->parameterSet = $set;
                    $this->em->persist($parameter);
                }
            }

            $this->em->flush();
        });
    }

    /**
     * Valide le schéma et vérifie l'empreinte de chaque fichier du périmètre, avant
     * tout chargement (C3 « avant tout chargement », C4). Retourne les CSV déjà lus.
     *
     * @return array<string, array{header: list<string>, rows: list<array<string, string>>}>
     */
    private function validateAndVerify(string $dataDir): array
    {
        $parsed = [];
        foreach (self::FILE_TO_SCHEMA as $file => $schemaName) {
            $schema = $this->loadSchema($dataDir, $schemaName);
            $csv = $this->csvReader->read(sprintf('%s/data/%s.csv', $dataDir, $file));
            $this->validator->validate($schema, $csv['header'], $csv['rows'], $file . '.csv');
            $this->manifestVerifier->verify($dataDir, $file);
            $parsed[$file] = $csv;
        }

        return $parsed;
    }

    /**
     * @param array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $referential
     */
    private function createSource(string $slug, array $referential): Source
    {
        if (!isset($referential[$slug])) {
            throw new UnknownSourceException(sprintf('source inconnue `%s`', $slug));
        }

        $data = $referential[$slug];
        $source = new Source();
        $source->code = $slug;
        $source->label = $data['label'];
        $source->publisher = $data['publisher'];
        $source->url = $data['url'];
        $source->retrievedAt = new \DateTimeImmutable($data['retrievedAt']);
        $source->fileHash = $data['fileHash'];
        $this->em->persist($source);

        return $source;
    }

    /**
     * @return array{fields?: list<array{name?: string, type?: string, constraints?: array{required?: bool}}>}
     */
    private function loadSchema(string $dataDir, string $schemaName): array
    {
        $path = sprintf('%s/schemas/%s.json', $dataDir, $schemaName);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('schéma introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('schéma invalide : %s', $path));
        }

        /** @var array{fields?: list<array{name?: string, type?: string, constraints?: array{required?: bool}}>} $decoded */
        return $decoded;
    }
}
