<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Validation Frictionless minimale maison (pas de lib Frictionless en PHP, cf. #11).
 *
 * Contrôle, contre un schéma `data/schemas/<schéma>.json` : présence des colonnes
 * requises, puis typage des valeurs non vides (`number`, `integer`, `year`,
 * `boolean`, `date`). Les colonnes non requises peuvent être vides (ex. `end_date`).
 * Les invariants métier (croissance, non-chevauchement, bornes) restent côté Python.
 */
final class FrictionlessSchemaValidator
{
    /**
     * @param array{fields?: list<array{name?: string, type?: string, constraints?: array{required?: bool}}>} $schema
     * @param list<string>                                                                                    $header
     * @param list<array<string, string>>                                                                     $rows
     */
    public function validate(array $schema, array $header, array $rows, string $file): void
    {
        $fields = $schema['fields'] ?? [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($this->isRequired($field) && !in_array($name, $header, true)) {
                throw new SchemaValidationException(
                    sprintf('%s : colonne requise `%s` manquante', $file, $name),
                );
            }
        }

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            foreach ($fields as $field) {
                $name = $field['name'] ?? '';
                $type = $field['type'] ?? 'string';
                $value = $row[$name] ?? '';

                if ($value === '') {
                    if ($this->isRequired($field)) {
                        throw new SchemaValidationException(sprintf(
                            '%s ligne %d : valeur requise manquante pour la colonne `%s`',
                            $file,
                            $line,
                            $name,
                        ));
                    }

                    continue;
                }

                if (!$this->matchesType($type, $value)) {
                    throw new SchemaValidationException(sprintf(
                        '%s ligne %d : valeur `%s` invalide pour la colonne `%s` (type `%s` attendu)',
                        $file,
                        $line,
                        $value,
                        $name,
                        $type,
                    ));
                }
            }
        }
    }

    /**
     * @param array{name?: string, type?: string, constraints?: array{required?: bool}} $field
     */
    private function isRequired(array $field): bool
    {
        return ($field['constraints']['required'] ?? false) === true;
    }

    private function matchesType(string $type, string $value): bool
    {
        return match ($type) {
            'number' => is_numeric($value),
            'integer' => preg_match('/^-?\d+$/', $value) === 1,
            'year' => preg_match('/^\d{4}$/', $value) === 1,
            'boolean' => in_array(strtolower($value), ['true', 'false'], true),
            'date' => $this->isIsoDate($value),
            default => true, // `string` et tout type non contraint
        };
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
