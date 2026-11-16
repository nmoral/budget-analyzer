<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel des académies (`data/academies.json`) : code officiel → métadonnées
 * (`label`, `statut` ∈ metropole/drom/com, `source`, `aliases` éventuels).
 *
 * Tous les champs de l'entité `Academie` étant NOT NULL, ce référentiel doit fournir
 * `label`, `statut` et `source` pour chaque entrée. Un code absent → refus. Le `statut`
 * doit valoir exactement `metropole`, `drom` ou `com` (casse et espaces compris) ;
 * absent ou autre → `InvalidAcademieStatutException`.
 */
final class AcademieReferential
{
    private const STATUTS = ['metropole', 'drom', 'com'];

    /**
     * @return array<string, array{label: string, statut: string, source: string, aliases?: list<string>}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/academies.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des académies introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des académies invalide : %s', $path));
        }

        foreach ($decoded as $code => $entry) {
            $this->assertStatut((string) $code, $entry);
        }

        /** @var array<string, array{label: string, statut: string, source: string, aliases?: list<string>}> $decoded */
        return $decoded;
    }

    private function assertStatut(string $code, mixed $entry): void
    {
        if (!is_array($entry) || !isset($entry['statut'])) {
            throw new InvalidAcademieStatutException(sprintf("statut d'académie absent (%s)", $code));
        }
        $statut = $entry['statut'];
        if (!in_array($statut, self::STATUTS, true)) {
            throw new InvalidAcademieStatutException(sprintf(
                "statut d'académie invalide « %s » (%s)",
                is_string($statut) ? $statut : json_encode($statut, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $code,
            ));
        }
    }
}
