<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\DatedParameter;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\PrimeRate;
use App\Infrastructure\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestration de l'import du dépôt de données vers Postgres (J1 + J2 + J3).
 *
 * Tout se joue dans une seule transaction (atomicité, C2) : validation Frictionless
 * de tous les CSV du périmètre d'import (C3), vérification des empreintes des bruts
 * (C4), puis création du `ParameterSet` versionné (C1) et chargement complet du
 * dépôt réel (C5) : valeurs datées (`DatedParameter`), grilles (`Grille`), échelons
 * (`Echelon`, rattachés à leur grille par la clé `{body_code}_{grade}_{version}`) et
 * taux de primes (`PrimeRate`), avec résolution des FK vers les référentiels `Source`
 * (`data/sources.json`) et `Corps` (`data/corps.json`) et vers le `ParameterSet`.
 *
 * Un slug/corps référencé mais absent de son référentiel → refus (rollback total).
 *
 * Réimport idempotent du même commit (C6, décision Q5) : en tête de transaction, les
 * entités scopées au `ParameterSet` du commit sont purgées puis rechargées, tandis que
 * les référentiels globaux `Source`/`Corps` sont mis à jour par upsert sur `code`.
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
        'taux_autres_cotisations_fpe' => 'parametre_date',
        'taux_autres_cotisations_fph' => 'parametre_date',
        'taux_rafp' => 'parametre_date',
        'echelon_pe' => 'echelon',
        'echelon_isg' => 'echelon',
        'grille_pe' => 'grille',
        'grille_isg' => 'grille',
        'taux_primes' => 'taux_primes',
    ];

    /** @var list<string> */
    private const DATED_PARAMETER_FILES = [
        'point_indice',
        'taux_cas_civil',
        'taux_cas_militaire',
        'taux_cnracl',
        'taux_autres_cotisations_fpe',
        'taux_autres_cotisations_fph',
        'taux_rafp',
    ];

    /** @var list<string> */
    private const GRILLE_FILES = ['grille_pe', 'grille_isg'];

    /** @var list<string> */
    private const ECHELON_FILES = ['echelon_pe', 'echelon_isg'];

    /** @var list<string> */
    private const PRIME_RATE_FILES = ['taux_primes'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsvReader $csvReader,
        private readonly FrictionlessSchemaValidator $validator,
        private readonly ManifestVerifier $manifestVerifier,
        private readonly SourceReferential $sourceReferential,
        private readonly CorpsReferential $corpsReferential,
    ) {
    }

    /**
     * @throws ImportException tout échec (schéma, empreinte, source/corps inconnu) → rollback total
     */
    public function import(string $commit, string $dataDir): void
    {
        $this->em->wrapInTransaction(function () use ($commit, $dataDir): void {
            $parsed = $this->validateAndVerify($dataDir);
            $sourceReferential = $this->sourceReferential->load($dataDir);
            $corpsReferential = $this->corpsReferential->load($dataDir);

            // Reproductibilité (C6, décision Q5) : repartir d'un état propre pour ce
            // commit. Les valeurs datées scopées (DatedParameter/Grille/Echelon/PrimeRate)
            // du ParameterSet existant sont purgées, puis le set est recréé. Les
            // référentiels globaux (Source/Corps) sont conservés et mis à jour par
            // upsert sur `code`. Le tout dans la même transaction (atomicité préservée).
            $this->purgeExistingCommit($commit);

            $set = new ParameterSet();
            $set->versionId = $commit;
            $set->importedAt = new \DateTimeImmutable();
            $this->em->persist($set);

            /** @var array<string, Source> $sources */
            $sources = [];
            /** @var array<string, Corps> $corps */
            $corps = [];
            /** @var array<string, Grille> $grilles */
            $grilles = [];

            $this->loadDatedParameters($parsed, $set, $sourceReferential, $sources);
            $this->loadGrilles($parsed, $set, $sourceReferential, $corpsReferential, $sources, $corps, $grilles);
            $this->loadEchelons($parsed, $grilles);
            $this->loadPrimeRates($parsed, $set, $sourceReferential, $corpsReferential, $sources, $corps);

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
     * @param array<string, array{header: list<string>, rows: list<array<string, string>>}>                   $parsed
     * @param array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $sourceReferential
     * @param array<string, Source>                                                                           $sources
     */
    private function loadDatedParameters(array $parsed, ParameterSet $set, array $sourceReferential, array &$sources): void
    {
        foreach (self::DATED_PARAMETER_FILES as $file) {
            foreach ($parsed[$file]['rows'] as $row) {
                $parameter = new DatedParameter();
                $parameter->key = $row['key'];
                $parameter->value = $row['value'];
                $parameter->unit = $row['unit'];
                $parameter->startDate = new \DateTimeImmutable($row['start_date']);
                $parameter->endDate = $row['end_date'] === '' ? null : new \DateTimeImmutable($row['end_date']);
                $parameter->source = $this->resolveSource($row['source'], $sourceReferential, $sources);
                $parameter->parameterSet = $set;
                $this->em->persist($parameter);
            }
        }
    }

    /**
     * @param array<string, array{header: list<string>, rows: list<array<string, string>>}>                   $parsed
     * @param array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $sourceReferential
     * @param array<string, array{label: string, versant: string, category: string}>                         $corpsReferential
     * @param array<string, Source>                                                                           $sources
     * @param array<string, Corps>                                                                            $corps
     * @param array<string, Grille>                                                                           $grilles
     */
    private function loadGrilles(
        array $parsed,
        ParameterSet $set,
        array $sourceReferential,
        array $corpsReferential,
        array &$sources,
        array &$corps,
        array &$grilles,
    ): void {
        foreach (self::GRILLE_FILES as $file) {
            foreach ($parsed[$file]['rows'] as $row) {
                $grille = new Grille();
                $grille->body = $this->resolveCorps($row['body_code'], $corpsReferential, $corps);
                $grille->grade = $row['grade'];
                $grille->version = $row['version'];
                $grille->startDate = new \DateTimeImmutable($row['start_date']);
                $grille->endDate = $row['end_date'] === '' ? null : new \DateTimeImmutable($row['end_date']);
                $grille->source = $this->resolveSource($row['source'], $sourceReferential, $sources);
                $grille->parameterSet = $set;
                $this->em->persist($grille);

                $grilles[$this->grilleKey($row['body_code'], $row['grade'], $row['version'])] = $grille;
            }
        }
    }

    /**
     * @param array<string, array{header: list<string>, rows: list<array<string, string>>}> $parsed
     * @param array<string, Grille>                                                          $grilles
     */
    private function loadEchelons(array $parsed, array $grilles): void
    {
        foreach (self::ECHELON_FILES as $file) {
            foreach ($parsed[$file]['rows'] as $row) {
                $key = $row['grille'];
                if (!isset($grilles[$key])) {
                    throw new ImportException(sprintf('grille inconnue `%s` (fichier %s.csv)', $key, $file));
                }

                $echelon = new Echelon();
                $echelon->grille = $grilles[$key];
                $echelon->rank = (int) $row['rank'];
                $echelon->grossIndex = (int) $row['gross_index'];
                $echelon->increasedIndex = (int) $row['increased_index'];
                $echelon->durationMonths = $row['duration_months'] === '' ? null : (int) $row['duration_months'];
                $this->em->persist($echelon);
            }
        }
    }

    /**
     * @param array<string, array{header: list<string>, rows: list<array<string, string>>}>                   $parsed
     * @param array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $sourceReferential
     * @param array<string, array{label: string, versant: string, category: string}>                         $corpsReferential
     * @param array<string, Source>                                                                           $sources
     * @param array<string, Corps>                                                                            $corps
     */
    private function loadPrimeRates(
        array $parsed,
        ParameterSet $set,
        array $sourceReferential,
        array $corpsReferential,
        array &$sources,
        array &$corps,
    ): void {
        foreach (self::PRIME_RATE_FILES as $file) {
            foreach ($parsed[$file]['rows'] as $row) {
                $rate = new PrimeRate();
                $rate->body = $this->resolveCorps($row['body_code'], $corpsReferential, $corps);
                $rate->value = $row['value'];
                $rate->observationYear = (int) $row['observation_year'];
                $rate->source = $this->resolveSource($row['source'], $sourceReferential, $sources);
                $rate->fallback = strtolower($row['fallback']) === 'true';
                $rate->parameterSet = $set;
                $this->em->persist($rate);
            }
        }
    }

    /**
     * Purge les entités scopées du ParameterSet de ce commit puis le set lui-même,
     * s'il existe déjà (réimport). Échelons d'abord (FK vers Grille), puis Grille /
     * DatedParameter / PrimeRate (FK vers le set), enfin le ParameterSet. Les Source
     * et Corps globaux ne sont pas touchés. L'identité est réinitialisée pour éviter
     * tout état résiduel avant rechargement.
     */
    private function purgeExistingCommit(string $commit): void
    {
        $existing = $this->em->getRepository(ParameterSet::class)->findOneBy(['versionId' => $commit]);
        if (!$existing instanceof ParameterSet || $existing->id === null) {
            return;
        }

        $setId = $existing->id;

        $deletes = [
            'DELETE App\Infrastructure\Entity\Echelon e WHERE IDENTITY(e.grille) IN '
                . '(SELECT g.id FROM App\Infrastructure\Entity\Grille g WHERE IDENTITY(g.parameterSet) = :set)',
            'DELETE App\Infrastructure\Entity\Grille g WHERE IDENTITY(g.parameterSet) = :set',
            'DELETE App\Infrastructure\Entity\DatedParameter p WHERE IDENTITY(p.parameterSet) = :set',
            'DELETE App\Infrastructure\Entity\PrimeRate r WHERE IDENTITY(r.parameterSet) = :set',
            'DELETE App\Infrastructure\Entity\ParameterSet s WHERE s.id = :set',
        ];
        foreach ($deletes as $dql) {
            $this->em->createQuery($dql)->setParameter('set', $setId)->execute();
        }

        $this->em->clear();
    }

    private function grilleKey(string $bodyCode, string $grade, string $version): string
    {
        return sprintf('%s_%s_%s', $bodyCode, $grade, $version);
    }

    /**
     * @param array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $referential
     * @param array<string, Source>                                                                                       $cache
     */
    private function resolveSource(string $slug, array $referential, array &$cache): Source
    {
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        // Le référentiel `data/sources.json` fait foi : un slug référencé mais absent
        // est refusé (C5.3), même si une ligne `source` de même code existait en base.
        if (!isset($referential[$slug])) {
            throw new UnknownSourceException(sprintf('source inconnue `%s`', $slug));
        }

        $data = $referential[$slug];
        // Upsert par `code` (C6, Q5) : réutilise la ligne existante, sinon la crée.
        $source = $this->em->getRepository(Source::class)->findOneBy(['code' => $slug]);
        if (!$source instanceof Source) {
            $source = new Source();
            $source->code = $slug;
            $this->em->persist($source);
        }
        $source->label = $data['label'];
        $source->publisher = $data['publisher'];
        $source->url = $data['url'];
        $source->retrievedAt = new \DateTimeImmutable($data['retrievedAt']);
        $source->fileHash = $data['fileHash'];

        return $cache[$slug] = $source;
    }

    /**
     * @param array<string, array{label: string, versant: string, category: string}> $referential
     * @param array<string, Corps>                                                    $cache
     */
    private function resolveCorps(string $code, array $referential, array &$cache): Corps
    {
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        if (!isset($referential[$code])) {
            throw new UnknownCorpsException(sprintf('corps inconnu `%s`', $code));
        }

        $data = $referential[$code];
        // Upsert par `code` (C6, Q5) : réutilise la ligne existante, sinon la crée.
        $corps = $this->em->getRepository(Corps::class)->findOneBy(['code' => $code]);
        if (!$corps instanceof Corps) {
            $corps = new Corps();
            $corps->code = $code;
            $this->em->persist($corps);
        }
        $corps->label = $data['label'];
        $corps->versant = $data['versant'];
        $corps->category = $data['category'];

        return $cache[$code] = $corps;
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
