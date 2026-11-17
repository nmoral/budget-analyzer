<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #62 — C4 (end-to-end) : `app:import` du dépôt de données, puis l'API HTTP
 * référence un profil importé par son **code**. Aucun profil fait main : seuls les
 * profils de `data/profiles.json` entrés par l'import sont utilisés.
 *
 * Moyen de vérification réelle : `app:import`, puis `POST /api/scenarios` avec
 * `"profileRef": "PE_CN"` → 201, `GET /api/scenarios/{id}/result?views=BUDGETAIRE` → 200.
 */
final class ScenarioProfileCodeApiTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private const string RECRUITMENT = '2026-09-01';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->createScenarioSchema($this->em);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->dropScenarioSchema($this->em);
        $this->em->clear();
        $this->em->close();

        parent::tearDown();
    }

    /** #62 C4.1 — scénario PE par code : 201, puis résultat budgétaire 200 avec un coût. */
    public function testPeScenarioByCodeIsCreatedAndComputed(): void
    {
        $this->import('e2e-set-1', $this->dataDir());

        $response = $this->postScenario(['lines' => [$this->line('PE_CN', 10)]]);
        self::assertSame(201, $response['status'], (string) json_encode($response['body']));
        $id = $response['body']['id'];
        self::assertIsInt($id);
        self::assertSame('e2e-set-1', $response['body']['parametersVersion']);

        $this->assertComputedWithACost($id, 'PE_CN');
    }

    /** #62 C4.2 — scénario ISG par code : 201, résultat 200. */
    public function testIsgScenarioByCodeIsCreatedAndComputed(): void
    {
        $this->import('e2e-set-1', $this->dataDir());

        $response = $this->postScenario(['lines' => [$this->line('ISG_G1', 1)]]);
        self::assertSame(201, $response['status'], (string) json_encode($response['body']));
        $id = $response['body']['id'];
        self::assertIsInt($id);

        $this->assertComputedWithACost($id, 'ISG_G1');
    }

    /** #62 C4.3 — code inconnu : 422, le message nomme le code. */
    public function testUnknownProfileCodeIsRejected(): void
    {
        $this->import('e2e-set-1', $this->dataDir());

        $response = $this->postScenario(['lines' => [$this->line('NOPE', 1)]]);

        self::assertSame(422, $response['status']);
        $error = $response['body']['error'] ?? null;
        self::assertIsString($error);
        self::assertStringContainsString('NOPE', $error);
        self::assertSame(0, $this->scenarioCount());
    }

    /**
     * #62 C4.4 — `parametersVersion` = set 1, `profileRef` = code existant seulement dans
     * le set 2 : 422.
     */
    public function testProfileOfAnotherParameterSetIsRejected(): void
    {
        $this->import('e2e-set-1', $this->dataDir());
        $dir = $this->dataDirWithExtraProfile('PE_SET2');
        $this->import('e2e-set-2', $dir);

        $response = $this->postScenario([
            'parametersVersion' => 'e2e-set-1',
            'lines' => [$this->line('PE_SET2', 1)],
        ]);

        self::assertSame(422, $response['status']);
        $error = $response['body']['error'] ?? null;
        self::assertIsString($error);
        self::assertStringContainsString('PE_SET2', $error);

        // Le même code est accepté sur le jeu qui le porte.
        $accepted = $this->postScenario([
            'parametersVersion' => 'e2e-set-2',
            'lines' => [$this->line('PE_SET2', 1)],
        ]);
        self::assertSame(201, $accepted['status'], (string) json_encode($accepted['body']));
    }

    /** #62 C4.5 — ancien format `profileRef` entier : 422 (plus accepté). */
    public function testIntegerProfileRefIsRejected(): void
    {
        $this->import('e2e-set-1', $this->dataDir());

        $response = $this->postScenario([
            'lines' => [['profileRef' => 12, 'volume' => 1, 'recruitmentDate' => self::RECRUITMENT]],
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame(0, $this->scenarioCount());
    }

    /** #62 C4.6 — lecture : `GET /api/scenarios/{id}` renvoie le code du profil. */
    public function testReadingAScenarioReturnsTheProfileCode(): void
    {
        $this->import('e2e-set-1', $this->dataDir());
        $created = $this->postScenario(['lines' => [$this->line('PE_CN', 10)]]);
        self::assertSame(201, $created['status'], (string) json_encode($created['body']));
        $id = $created['body']['id'];
        self::assertIsInt($id);

        $this->client->request('GET', '/api/scenarios/' . $id);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{lines: list<array<string, mixed>>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('PE_CN', $body['lines'][0]['profileRef']);
        self::assertSame(10, $body['lines'][0]['volume']);
    }

    private function assertComputedWithACost(int $id, string $code): void
    {
        $this->client->request('GET', '/api/scenarios/' . $id . '/result?views=BUDGETAIRE');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{lines: list<array{profileRef: mixed, results: array<string, array{year1: array{fullYear: array{total: string}}}>}>} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame($code, $body['lines'][0]['profileRef']);
        $total = $body['lines'][0]['results']['BUDGETAIRE']['year1']['fullYear']['total'];
        self::assertIsNumeric($total);
        self::assertGreaterThan(0, (float) $total);
    }

    /**
     * @return array{profileRef: string, volume: int, recruitmentDate: string}
     */
    private function line(string $code, int $volume): array
    {
        return ['profileRef' => $code, 'volume' => $volume, 'recruitmentDate' => self::RECRUITMENT];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postScenario(array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['title' => 'E2E #62'] + $payload),
        );
        $response = $this->client->getResponse();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    private function import(string $commit, string $dataDir): void
    {
        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:import'));
        $tester->execute(['commit' => $commit, '--data-dir' => $dataDir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function scenarioCount(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM scenario');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** Copie du dépôt de données dont `profiles.json` porte en plus `$code` (copie de PE_CN). */
    private function dataDirWithExtraProfile(string $code): string
    {
        $dir = sys_get_temp_dir() . '/e2e-profile-code-' . uniqid('', true);
        $this->tempDirs[] = $dir;
        $this->copyDir($this->dataDir(), $dir);

        $raw = file_get_contents($dir . '/profiles.json');
        self::assertIsString($raw);
        $profiles = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($profiles);
        self::assertIsArray($profiles['PE_CN']);
        $profiles[$code] = $profiles['PE_CN'];
        file_put_contents($dir . '/profiles.json', json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $dir;
    }

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0o777, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, 0o777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
