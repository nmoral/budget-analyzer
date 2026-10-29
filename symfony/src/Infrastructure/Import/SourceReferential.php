<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des sources (`data/sources.json`) : slug → métadonnées
 * complètes (`label`, `publisher`, `url`, `retrievedAt`, `fileHash`).
 *
 * Tous les champs de l'entité `Source` étant NOT NULL, ce référentiel doit les
 * fournir pour chaque slug référencé par la colonne `source` d'un CSV (décision Q3).
 */
final class SourceReferential
{
    /**
     * @return array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}>
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

        /** @var array<string, array{label: string, publisher: string, url: string, retrievedAt: string, fileHash: string}> $decoded */
        return $decoded;
    }
}
