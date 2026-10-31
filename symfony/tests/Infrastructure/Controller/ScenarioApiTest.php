<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API scénarios (#19, J1) : création (C1), lecture (C2) et erreurs de base (C7).
 *
 * Tests fonctionnels bout en bout (HTTP + Postgres de test), le schéma étant
 * (re)créé à chaque cas depuis le mapping ORM.
 */
final class ScenarioApiTest extends WebTestCase
{
    use ScenarioFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

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
        $this->dropScenarioSchema($this->em);
        $this->em->clear();
        $this->em->close();

        parent::tearDown();
    }

    /**
     * C1.1 : création nominale → 201 {id, parametersVersion} ; Scenario + ligne persistés.
     */
    public function testCreateScenarioReturns201WithIdAndPinnedVersion(): void
    {
        $set = $this->persistParameterSet($this->em, 'commit-c1-1');
        $profile = $this->persistProfile($this->em, $set);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Rentrée 2026',
                'lines' => [[
                    'profileRef' => $profile->id,
                    'volume' => 1,
                    'recruitmentDate' => '2026-09-01',
                ]],
            ]),
        );

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsInt($body['id']);
        self::assertSame('commit-c1-1', $body['parametersVersion']);
    }

    /**
     * C1.1 (persistance) : la ligne est bien enregistrée avec son profil et son volume.
     */
    public function testCreatedScenarioIsReadableWithItsLine(): void
    {
        $set = $this->persistParameterSet($this->em, 'commit-read');
        $profile = $this->persistProfile($this->em, $set);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Programme',
                'lines' => [[
                    'profileRef' => $profile->id,
                    'volume' => 3,
                    'recruitmentDate' => '2026-09-01',
                    'spreadYears' => 2,
                ]],
            ]),
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $created */
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $createdId = $created['id'];
        self::assertIsInt($createdId);

        // C2.1 : lecture → 200 avec la ligne.
        $this->client->request('GET', '/api/scenarios/' . $createdId);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{id:int, title:string, parametersVersion:string, lines:list<array<string,mixed>>} $body */
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame($created['id'], $body['id']);
        self::assertSame('Programme', $body['title']);
        self::assertSame('commit-read', $body['parametersVersion']);
        self::assertCount(1, $body['lines']);
        self::assertSame($profile->id, $body['lines'][0]['profileRef']);
        self::assertSame(3, $body['lines'][0]['volume']);
        self::assertSame('2026-09-01', $body['lines'][0]['recruitmentDate']);
        self::assertSame(2, $body['lines'][0]['spreadYears']);
    }

    /**
     * C1.2 : version inexistante à la création → 422.
     */
    public function testCreateWithUnknownParametersVersionReturns422(): void
    {
        $set = $this->persistParameterSet($this->em, 'commit-c1-2');
        $profile = $this->persistProfile($this->em, $set);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Rentrée 2026',
                'parametersVersion' => 'zzz',
                'lines' => [[
                    'profileRef' => $profile->id,
                    'volume' => 1,
                    'recruitmentDate' => '2026-09-01',
                ]],
            ]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /**
     * C1 : `parametersVersion` fourni et connu → épingle ce jeu, pas le dernier importé.
     */
    public function testCreateWithKnownParametersVersionPinsThatVersion(): void
    {
        $older = $this->persistParameterSet($this->em, 'commit-older', '2026-01-01');
        $latest = $this->persistParameterSet($this->em, 'commit-latest', '2026-06-01');
        $profile = $this->persistProfile($this->em, $latest);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Épinglé',
                'parametersVersion' => 'commit-older',
                'lines' => [[
                    'profileRef' => $profile->id,
                    'volume' => 1,
                    'recruitmentDate' => '2026-09-01',
                ]],
            ]),
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('commit-older', $body['parametersVersion']);
        // Le jeu "latest" existe mais n'a pas été choisi.
        self::assertNotNull($older->id);
    }

    /**
     * C1 : sans `parametersVersion`, le scénario est épinglé au dernier jeu importé.
     */
    public function testCreateWithoutVersionPinsLatestParameterSet(): void
    {
        $this->persistParameterSet($this->em, 'commit-older', '2026-01-01');
        $latest = $this->persistParameterSet($this->em, 'commit-latest', '2026-06-01');
        $profile = $this->persistProfile($this->em, $latest);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/scenarios',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'title' => 'Défaut',
                'lines' => [[
                    'profileRef' => $profile->id,
                    'volume' => 1,
                    'recruitmentDate' => '2026-09-01',
                ]],
            ]),
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('commit-latest', $body['parametersVersion']);
    }

    /**
     * C2.1 : scénario inconnu en lecture → 404.
     */
    public function testGetUnknownScenarioReturns404(): void
    {
        $this->client->request('GET', '/api/scenarios/999999');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
