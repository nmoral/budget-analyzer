<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des profils (`data/profiles.json`) : code → `label`, `body`
 * (code corps), `grade`, `entryRank` (rang de l'échelon d'entrée), `retirementScheme`,
 * `funder`, `status`, `referenceCalendar` et `source` (slug de `data/sources.json`).
 *
 * Chaque import crée les profils du ParameterSet importé (#62) ; toute référence
 * absente de son référentiel → refus (rollback total).
 */
final class ProfileReferential
{
    /**
     * @return array<string, array{label: string, body: string, grade: string, entryRank: int, retirementScheme: string, funder: string, status: string, referenceCalendar: string, source: string}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/profiles.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des profils introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des profils invalide : %s', $path));
        }

        /** @var array<string, array{label: string, body: string, grade: string, entryRank: int, retirementScheme: string, funder: string, status: string, referenceCalendar: string, source: string}> $decoded */
        return $decoded;
    }
}
