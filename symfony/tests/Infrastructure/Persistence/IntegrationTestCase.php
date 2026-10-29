<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\DatedParameter;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\PrimeRate;
use App\Infrastructure\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests d'intégration touchant le Postgres réel de la stack.
 *
 * Le schéma est (re)créé depuis le mapping ORM avant chaque test et supprimé
 * après, afin d'isoler chaque cas et de laisser la base vide pour les autres
 * tests (dont le contrôle « schéma public vide » hérité de #9).
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->dropSchemaCompletely();
        $this->schemaTool()->createSchema($this->metadata());
    }

    protected function tearDown(): void
    {
        $this->dropSchemaCompletely();
        $this->em->clear();
        $this->em->close();

        parent::tearDown();
    }

    /**
     * Supprime les tables mappées par l'ORM *et* la table de suivi des
     * migrations.
     *
     * `SchemaTool::dropSchema()` ne connaît que le mapping ORM : il laisse
     * intacte `doctrine_migration_versions` (gérée par doctrine/migrations, hors
     * mapping). On purge donc aussi cette table pour laisser le schéma `public`
     * réellement vide après chaque test, et ainsi garder déterministe le contrôle
     * « schéma public vide » (DatabaseConnectionTest). La suite opère sur la base
     * de test dédiée `budget_test` (suffixe `_test`, cf. `when@test` dans
     * `config/packages/doctrine.yaml`), jamais sur la base de dev `budget`.
     */
    private function dropSchemaCompletely(): void
    {
        $this->schemaTool()->dropSchema($this->metadata());
        $this->em->getConnection()->executeStatement(
            'DROP TABLE IF EXISTS doctrine_migration_versions',
        );
    }

    protected function newSource(string $code): Source
    {
        $source = new Source();
        $source->code = $code;
        $source->label = 'Source ' . $code;
        $source->publisher = 'Éditeur';
        $source->url = 'https://example.test/' . $code;
        $source->retrievedAt = new \DateTimeImmutable('2024-01-01');
        $source->fileHash = 'hash-' . $code;
        $this->em->persist($source);

        return $source;
    }

    protected function newParameterSet(string $versionId, string $importedAt): ParameterSet
    {
        $set = new ParameterSet();
        $set->versionId = $versionId;
        $set->importedAt = new \DateTimeImmutable($importedAt);
        $this->em->persist($set);

        return $set;
    }

    protected function newCorps(string $code): Corps
    {
        $corps = new Corps();
        $corps->code = $code;
        $corps->label = 'Corps ' . $code;
        $corps->versant = 'FPE';
        $corps->category = 'A';
        $this->em->persist($corps);

        return $corps;
    }

    protected function newDatedParameter(
        string $key,
        string $value,
        string $startDate,
        ?string $endDate,
        Source $source,
        ParameterSet $set,
    ): DatedParameter {
        $param = new DatedParameter();
        $param->key = $key;
        $param->value = $value;
        $param->unit = 'UNIT';
        $param->startDate = new \DateTimeImmutable($startDate);
        $param->endDate = $endDate === null ? null : new \DateTimeImmutable($endDate);
        $param->source = $source;
        $param->parameterSet = $set;
        $this->em->persist($param);

        return $param;
    }

    protected function newGrille(
        Corps $body,
        string $grade,
        string $version,
        string $startDate,
        ?string $endDate,
        Source $source,
        ParameterSet $set,
    ): Grille {
        $grille = new Grille();
        $grille->body = $body;
        $grille->grade = $grade;
        $grille->version = $version;
        $grille->startDate = new \DateTimeImmutable($startDate);
        $grille->endDate = $endDate === null ? null : new \DateTimeImmutable($endDate);
        $grille->source = $source;
        $grille->parameterSet = $set;
        $this->em->persist($grille);

        return $grille;
    }

    protected function newEchelon(Grille $grille, int $rank, int $grossIndex, int $increasedIndex): Echelon
    {
        $echelon = new Echelon();
        $echelon->grille = $grille;
        $echelon->rank = $rank;
        $echelon->grossIndex = $grossIndex;
        $echelon->increasedIndex = $increasedIndex;
        $echelon->durationMonths = 12;
        $this->em->persist($echelon);

        return $echelon;
    }

    protected function newPrimeRate(
        Corps $body,
        string $value,
        int $observationYear,
        bool $fallback,
        Source $source,
        ParameterSet $set,
    ): PrimeRate {
        $rate = new PrimeRate();
        $rate->body = $body;
        $rate->value = $value;
        $rate->observationYear = $observationYear;
        $rate->fallback = $fallback;
        $rate->source = $source;
        $rate->parameterSet = $set;
        $this->em->persist($rate);

        return $rate;
    }

    /**
     * @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>>
     */
    private function metadata(): array
    {
        return $this->em->getMetadataFactory()->getAllMetadata();
    }

    private function schemaTool(): SchemaTool
    {
        return new SchemaTool($this->em);
    }
}
