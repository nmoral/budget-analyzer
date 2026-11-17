<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Issue #62 — C2 : migration qui ajoute à `profile` ses colonnes `code`, `label`,
 * `source_id`, `parameter_set_id` (NOT NULL, code unique par ParameterSet).
 *
 * Les migrations réelles sont jouées sur la base de test `budget_test`, schéma
 * remis à vide avant et après chaque cas.
 */
final class ProfileMigrationTest extends KernelTestCase
{
    /** Dernière migration antérieure à #62. */
    private const string PREVIOUS_VERSION = 'App\Migrations\Version20261116172020';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->emptySchema();
    }

    protected function tearDown(): void
    {
        $this->emptySchema();
        $this->em->close();

        parent::tearDown();
    }

    /** #62 C2.1 — migrations jouées depuis zéro : `doctrine:schema:validate` OK. */
    public function testMigratedSchemaIsInSyncWithMapping(): void
    {
        $tester = $this->migrate(null);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $validate = $this->console(['command' => 'doctrine:schema:validate']);
        self::assertSame(0, $validate->getStatusCode(), $validate->getDisplay());
        self::assertSame(
            ['code', 'label', 'parameter_set_id', 'source_id'],
            $this->profileColumns(['code', 'label', 'source_id', 'parameter_set_id'], 'NO'),
        );
    }

    /**
     * #62 C2.2 — un profil créé à la main avant la migration : la migration échoue
     * avec un message explicite (profils non importés à purger), sans rien supprimer.
     */
    public function testMigrationAbortsWhenHandMadeProfilesExist(): void
    {
        $previous = $this->migrate(self::PREVIOUS_VERSION);
        self::assertSame(0, $previous->getStatusCode(), $previous->getDisplay());
        $this->insertHandMadeProfile();

        $tester = $this->migrate(null);

        self::assertNotSame(0, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertIsString($display);
        self::assertStringContainsString('1 profil(s) non importé(s)', $display);
        self::assertStringContainsString('à purger', $display);

        self::assertSame(1, $this->rowCount('profile'));
        self::assertSame(1, $this->rowCount('retirement_scheme'));
        self::assertSame(1, $this->rowCount('funder'));
        self::assertSame([], $this->profileColumns(['code', 'label', 'source_id', 'parameter_set_id'], null));
    }

    private function migrate(?string $version): ApplicationTester
    {
        $input = ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true];
        if ($version !== null) {
            $input['version'] = $version;
        }

        return $this->console($input);
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function console(array $input): ApplicationTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false]);

        return $tester;
    }

    private function insertHandMadeProfile(): void
    {
        $conn = $this->connection();
        $conn->executeStatement(
            'INSERT INTO source (code, label, publisher, edition, locator, url, retrieved_at, file_hash) '
            . "VALUES ('s', 'S', 'P', '2026', 'p. 1', 'https://example.test', '2026-01-01', 'h')",
        );
        $conn->executeStatement(
            "INSERT INTO parameter_set (version_id, imported_at) VALUES ('v1', '2026-01-01 00:00:00')",
        );
        $conn->executeStatement(
            "INSERT INTO corps (code, label, versant, category) VALUES ('PE', 'PE', 'FPE', 'A')",
        );
        $conn->executeStatement(
            'INSERT INTO grille (body_id, grade, version, start_date, source_id, parameter_set_id) '
            . "SELECT c.id, 'classe_normale', '2024', '2024-01-01', s.id, p.id FROM corps c, source s, parameter_set p",
        );
        $conn->executeStatement(
            'INSERT INTO echelon (grille_id, rank, gross_index, increased_index, duration_months) '
            . 'SELECT id, 1, 444, 395, 12 FROM grille',
        );
        $conn->executeStatement(
            "INSERT INTO retirement_scheme (code, label) VALUES ('CAS_CIVIL', 'CAS civil')",
        );
        $conn->executeStatement("INSERT INTO funder (code, label) VALUES ('ETAT', 'État')");
        $conn->executeStatement(
            'INSERT INTO profile (body_id, grade, entry_echelon_id, retirement_scheme_id, funder_id, status, reference_calendar) '
            . "SELECT c.id, 'classe_normale', e.id, r.id, f.id, 'titulaire', 'scolaire' "
            . 'FROM corps c, echelon e, retirement_scheme r, funder f',
        );
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string> colonnes de `profile` présentes parmi `$columns` (filtrées
     *                      sur `is_nullable` si fourni), triées
     */
    private function profileColumns(array $columns, ?string $nullable): array
    {
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' "
            . "AND table_name = 'profile' AND column_name IN (?)";
        $params = [$columns];
        $types = [ArrayParameterType::STRING];
        if ($nullable !== null) {
            $sql .= ' AND is_nullable = ?';
            $params[] = $nullable;
            $types[] = ParameterType::STRING;
        }

        /** @var list<string> $found */
        $found = $this->connection()->fetchFirstColumn($sql . ' ORDER BY column_name', $params, $types);

        return $found;
    }

    private function rowCount(string $table): int
    {
        $value = $this->connection()->fetchOne(sprintf('SELECT count(*) FROM %s', $table));
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function emptySchema(): void
    {
        (new SchemaTool($this->em))->dropDatabase();
        $this->connection()->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
