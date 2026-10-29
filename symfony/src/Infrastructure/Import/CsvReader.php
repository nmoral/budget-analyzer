<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Lecteur CSV minimal : en-tête + lignes associatives (colonne → valeur).
 *
 * Les CSV du dépôt de données ne sont lus qu'à l'import (cf. objectif #11).
 */
final class CsvReader
{
    /**
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new ImportException(sprintf('fichier CSV introuvable : %s', $path));
        }

        try {
            $rawHeader = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($rawHeader)) {
                return ['header' => [], 'rows' => []];
            }

            $header = array_map(static fn (?string $value): string => (string) $value, $rawHeader);

            $rows = [];
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue; // ligne vide
                }

                $row = [];
                foreach ($header as $index => $name) {
                    $row[$name] = isset($cells[$index]) ? (string) $cells[$index] : '';
                }
                $rows[] = $row;
            }

            return ['header' => $header, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }
}
