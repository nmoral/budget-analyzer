<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Import;

use App\Infrastructure\Import\FrictionlessSchemaValidator;
use App\Infrastructure\Import\SchemaValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Validation Frictionless minimale (C3, et volet schéma de C2.1).
 */
final class FrictionlessSchemaValidatorTest extends TestCase
{
    private FrictionlessSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FrictionlessSchemaValidator();
    }

    /**
     * @return array{fields: list<array{name: string, type: string, constraints?: array{required?: bool}}>}
     */
    private function parametreDateSchema(): array
    {
        return [
            'fields' => [
                ['name' => 'key', 'type' => 'string', 'constraints' => ['required' => true]],
                ['name' => 'value', 'type' => 'number', 'constraints' => ['required' => true]],
                ['name' => 'unit', 'type' => 'string', 'constraints' => ['required' => true]],
                ['name' => 'start_date', 'type' => 'date', 'constraints' => ['required' => true]],
                ['name' => 'end_date', 'type' => 'date'],
                ['name' => 'source', 'type' => 'string', 'constraints' => ['required' => true]],
            ],
        ];
    }

    /**
     * @return array{fields: list<array{name: string, type: string, constraints?: array{required?: bool}}>}
     */
    private function grilleSchema(): array
    {
        return [
            'fields' => [
                ['name' => 'body_code', 'type' => 'string', 'constraints' => ['required' => true]],
                ['name' => 'grade', 'type' => 'string', 'constraints' => ['required' => true]],
                ['name' => 'version', 'type' => 'string', 'constraints' => ['required' => true]],
                ['name' => 'start_date', 'type' => 'date', 'constraints' => ['required' => true]],
                ['name' => 'end_date', 'type' => 'date'],
                ['name' => 'source', 'type' => 'string', 'constraints' => ['required' => true]],
            ],
        ];
    }

    /** C3.1 — CSV parametre_date conforme : validé, aucune exception. */
    public function testValidParametreDateRowPasses(): void
    {
        $header = ['key', 'value', 'unit', 'start_date', 'end_date', 'source'];
        $rows = [[
            'key' => 'point_indice',
            'value' => '59.0734',
            'unit' => 'EUR_PER_YEAR',
            'start_date' => '2023-07-01',
            'end_date' => '',
            'source' => 'decret-2023-519',
        ]];

        $this->validator->validate($this->parametreDateSchema(), $header, $rows, 'point_indice.csv');

        $this->addToAssertionCount(1);
    }

    /** C3.2 — colonne requise `start_date` absente : refus. */
    public function testMissingRequiredColumnStartDateIsRejected(): void
    {
        $header = ['key', 'value', 'unit', 'end_date', 'source'];
        $rows = [[
            'key' => 'point_indice',
            'value' => '59.0734',
            'unit' => 'EUR_PER_YEAR',
            'end_date' => '',
            'source' => 'decret-2023-519',
        ]];

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('colonne requise `start_date` manquante');

        $this->validator->validate($this->parametreDateSchema(), $header, $rows, 'point_indice.csv');
    }

    /** C3.3 — `value` non numérique : refus (type). */
    public function testNonNumericValueIsRejected(): void
    {
        $header = ['key', 'value', 'unit', 'start_date', 'end_date', 'source'];
        $rows = [[
            'key' => 'point_indice',
            'value' => 'pas-un-nombre',
            'unit' => 'EUR_PER_YEAR',
            'start_date' => '2023-07-01',
            'end_date' => '',
            'source' => 'decret-2023-519',
        ]];

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('type');

        $this->validator->validate($this->parametreDateSchema(), $header, $rows, 'point_indice.csv');
    }

    /** C2.1 (volet schéma) — grille sans la colonne requise `version` : refus. */
    public function testMissingRequiredColumnVersionInGrilleIsRejected(): void
    {
        $header = ['body_code', 'grade', 'start_date', 'end_date', 'source'];
        $rows = [[
            'body_code' => 'PE',
            'grade' => 'classe_normale',
            'start_date' => '2024-01-01',
            'end_date' => '',
            'source' => 'decret-2017-789',
        ]];

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('colonne requise `version` manquante');

        $this->validator->validate($this->grilleSchema(), $header, $rows, 'grille_pe.csv');
    }
}
