<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Garde-fou défensif du provisioning de test : n'autorise une action destructrice
 * (reset du schéma `public` via `DROP SCHEMA ... CASCADE`) que sur une base dont le
 * nom se termine par `_test`. Protège la base de dev `budget` si jamais le suffixe
 * `dbname_suffix` repassait à `''` (CLAUDE.md : aucune action destructrice hors base
 * de test).
 */
final class TestDatabaseGuard
{
    public static function isTestDatabase(?string $databaseName): bool
    {
        return is_string($databaseName) && str_ends_with($databaseName, '_test');
    }
}
