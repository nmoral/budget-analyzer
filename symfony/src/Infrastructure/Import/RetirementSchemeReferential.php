<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des régimes de retraite (`data/retirement_schemes.json`) :
 * code → `label`. Chargé en entier à l'import (upsert par code, #62).
 */
final class RetirementSchemeReferential
{
    /**
     * @return array<string, array{label: string}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/retirement_schemes.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des régimes de retraite introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des régimes de retraite invalide : %s', $path));
        }

        /** @var array<string, array{label: string}> $decoded */
        return $decoded;
    }
}
