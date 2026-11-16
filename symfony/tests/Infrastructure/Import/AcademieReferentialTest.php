<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Import;

use App\Infrastructure\Import\AcademieReferential;
use App\Infrastructure\Import\InvalidAcademieStatutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle du `statut` des entrées de `data/academies.json` au chargement (#82).
 */
final class AcademieReferentialTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/academie-referential-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/academies.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testEveryAllowedStatutIsLoaded(): void
    {
        $json = '{"01": {"statut": "metropole"}, "28": {"statut": "drom"}, "40": {"statut": "com"}}';
        file_put_contents($this->dir . '/academies.json', $json);

        $referential = (new AcademieReferential())->load($this->dir);

        self::assertSame(['metropole', 'drom', 'com'], array_column($referential, 'statut'));
    }

    #[DataProvider('malformedEntries')]
    public function testMalformedStatutIsRejected(string $entry, string $message): void
    {
        file_put_contents($this->dir . '/academies.json', '{"01": {"statut": "metropole"}, "32": ' . $entry . '}');

        $this->expectException(InvalidAcademieStatutException::class);
        $this->expectExceptionMessage($message);
        (new AcademieReferential())->load($this->dir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'statut null' => ['{"statut": null}', "statut d'académie absent (32)"];
        yield 'entrée non objet' => ['"drom"', "statut d'académie absent (32)"];
        yield 'statut numérique' => ['{"statut": 1}', "statut d'académie invalide « 1 » (32)"];
    }
}
