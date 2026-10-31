<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des sources (`data/sources.json`) : slug → métadonnées
 * complètes (`label`, `publisher`, `edition`, `locator`, `url`, `retrievedAt`,
 * `fileHash`).
 *
 * Tous les champs de l'entité `Source` étant NOT NULL, ce référentiel doit les
 * fournir pour chaque slug référencé par la colonne `source` d'un CSV (décision Q3).
 * `edition` (année/édition) et `locator` (localisation page/tableau/article) sont
 * requis depuis #65 (« source en clair complète »).
 */
final class SourceReferential
{
    /**
     * @return array<string, array{label: string, publisher: string, edition: string, locator: string, url: string, retrievedAt: string, fileHash: string}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/sources.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des sources introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des sources invalide : %s', $path));
        }

        /** @var array<string, array{label: string, publisher: string, edition: string, locator: string, url: string, retrievedAt: string, fileHash: string}> $decoded */
        return $decoded;
    }
}
