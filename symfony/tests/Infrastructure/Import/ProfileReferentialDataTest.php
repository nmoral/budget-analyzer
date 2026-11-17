<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Import;

use PHPUnit\Framework\TestCase;

/**
 * Issue #62 — C1 / C1.1 : référentiels réels `data/retirement_schemes.json` et
 * `data/profiles.json` du dépôt. 2 régimes, 2 profils ; chaque corps, grade, rang
 * d'entrée, régime, financeur et source cité par un profil existe dans les autres
 * référentiels de `data/` (corps.json, grilles et échelons, funders.json,
 * retirement_schemes.json, sources.json).
 */
final class ProfileReferentialDataTest extends TestCase
{
    /** #62 C1.1 — les deux régimes livrés, avec leur libellé. */
    public function testRetirementSchemesReferentialHoldsTheTwoDeliveredSchemes(): void
    {
        $schemes = $this->json('retirement_schemes.json');

        self::assertSame(['CAS_CIVIL', 'CNRACL'], array_keys($schemes));
        foreach ($schemes as $code => $scheme) {
            self::assertIsArray($scheme, $code);
            self::assertIsString($scheme['label'] ?? null, $code);
            self::assertNotSame('', $scheme['label'], $code);
        }
    }

    /** #62 C1.1 — les deux profils livrés, champ par champ. */
    public function testProfilesReferentialHoldsTheTwoDeliveredProfiles(): void
    {
        self::assertSame(
            [
                'PE_CN' => [
                    'label' => 'Professeur des écoles — classe normale',
                    'body' => 'PE',
                    'grade' => 'classe_normale',
                    'entryRank' => 1,
                    'retirementScheme' => 'CAS_CIVIL',
                    'funder' => 'ETAT_P140',
                    'status' => 'titulaire',
                    'referenceCalendar' => 'scolaire',
                    'source' => 'decret-2017-789',
                ],
                'ISG_G1' => [
                    'label' => 'Infirmier en soins généraux — 1er grade',
                    'body' => 'ISG',
                    'grade' => 'grade_1',
                    'entryRank' => 1,
                    'retirementScheme' => 'CNRACL',
                    'funder' => 'ONDAM',
                    'status' => 'titulaire',
                    'referenceCalendar' => 'civil',
                    'source' => 'arrete-2016-05-19',
                ],
            ],
            $this->json('profiles.json'),
        );
    }

    /** #62 C1.1 — toute référence citée par un profil existe dans `data/`. */
    public function testEveryReferenceCitedByAProfileExists(): void
    {
        $corps = $this->json('corps.json');
        $schemes = $this->json('retirement_schemes.json');
        $funders = $this->json('funders.json');
        $sources = $this->json('sources.json');
        $grilles = $this->csvRows('grille_*.csv');
        $echelons = $this->csvRows('echelon_*.csv');

        $profiles = $this->json('profiles.json');
        self::assertCount(2, $profiles);

        foreach ($profiles as $code => $profile) {
            self::assertIsArray($profile, $code);
            self::assertArrayHasKey($this->string($profile, 'body'), $corps, sprintf('%s : corps inconnu', $code));
            self::assertArrayHasKey($this->string($profile, 'retirementScheme'), $schemes, sprintf('%s : régime inconnu', $code));
            self::assertArrayHasKey($this->string($profile, 'funder'), $funders, sprintf('%s : financeur inconnu', $code));
            self::assertArrayHasKey($this->string($profile, 'source'), $sources, sprintf('%s : source inconnue', $code));

            $grilleKeys = [];
            foreach ($grilles as $grille) {
                if ($grille['body_code'] === $profile['body'] && $grille['grade'] === $profile['grade']) {
                    $grilleKeys[] = sprintf('%s_%s_%s', $grille['body_code'], $grille['grade'], $grille['version']);
                }
            }
            self::assertNotSame([], $grilleKeys, sprintf('%s : aucune grille pour (corps, grade)', $code));

            $entryRank = $profile['entryRank'] ?? null;
            self::assertIsInt($entryRank, $code);
            $ranks = [];
            foreach ($echelons as $echelon) {
                if (in_array($echelon['grille'], $grilleKeys, true)) {
                    $ranks[] = (int) $echelon['rank'];
                }
            }
            self::assertContains($entryRank, $ranks, sprintf('%s : rang d\'entrée absent des échelons', $code));
        }
    }

    /**
     * @param array<mixed> $entry
     */
    private function string(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;
        self::assertIsString($value, $key);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $file): array
    {
        $raw = file_get_contents($this->dataDir() . '/' . $file);
        self::assertIsString($raw, $file);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $file);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, string>>
     */
    private function csvRows(string $pattern): array
    {
        $files = glob($this->dataDir() . '/data/' . $pattern);
        self::assertIsArray($files);
        self::assertNotSame([], $files, $pattern);

        $rows = [];
        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            self::assertNotFalse($handle, $file);
            $header = fgetcsv($handle, escape: '');
            self::assertIsArray($header, $file);
            while (($line = fgetcsv($handle, escape: '')) !== false) {
                $row = array_combine(array_map('strval', $header), array_map('strval', $line));
                $rows[] = $row;
            }
            fclose($handle);
        }

        return $rows;
    }

    private function dataDir(): string
    {
        $dir = getenv('BUDGET_DATA_DIR');

        return $dir !== false && $dir !== '' ? $dir : '/data';
    }
}
