<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Exemple C2.1 : GET /health en env test, sans base de données.
 */
final class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOkStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getContent());
    }
}
