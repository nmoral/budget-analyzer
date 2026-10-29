<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des corps (`data/corps.json`) : code → métadonnées complètes
 * (`label`, `versant`, `category`).
 *
 * Tous les champs de l'entité `Corps` étant NOT NULL, ce référentiel doit les fournir
 * pour chaque `body_code` référencé par un CSV (décision Q3). Un code absent → refus.
 */
final class CorpsReferential
{
    /**
     * @return array<string, array{label: string, versant: string, category: string}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/corps.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des corps introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des corps invalide : %s', $path));
        }

        /** @var array<string, array{label: string, versant: string, category: string}> $decoded */
        return $decoded;
    }
}
