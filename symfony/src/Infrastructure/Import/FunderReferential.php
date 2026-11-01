<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Référentiel curaté des financeurs (`data/funders.json`) : code → métadonnées
 * (`label` requis, puis les codes de nomenclature officielle, tous optionnels :
 * `mission`, `program`, `action` pour l'État, `ondamSubObjective` pour l'ONDAM,
 * `cofog`).
 *
 * `label` étant NOT NULL sur l'entité `Funder`, ce référentiel doit le fournir pour
 * chaque `funder_code` référencé par un CSV (décision Q1 #70). Les autres colonnes de
 * `Funder` sont nullable : seules celles présentes dans le référentiel sont renseignées.
 * Un code absent → refus.
 */
final class FunderReferential
{
    /**
     * @return array<string, array{label: string, mission?: string, program?: string, action?: string, ondamSubObjective?: string, cofog?: string}>
     */
    public function load(string $dataDir): array
    {
        $path = sprintf('%s/funders.json', $dataDir);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ImportException(sprintf('référentiel des financeurs introuvable : %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ImportException(sprintf('référentiel des financeurs invalide : %s', $path));
        }

        /** @var array<string, array{label: string, mission?: string, program?: string, action?: string, ondamSubObjective?: string, cofog?: string}> $decoded */
        return $decoded;
    }
}
