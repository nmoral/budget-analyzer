<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

/**
 * Sélection de résultat invalide (vue ou horizon inconnu) : mappée en 422 par le
 * contrôleur. Distincte des erreurs de données manquantes.
 */
final class InvalidSelectionException extends \RuntimeException
{
    public static function unknownView(string $code): self
    {
        return new self(sprintf('Vue de coût inconnue : « %s ».', $code));
    }

    public static function unknownHorizon(string $code): self
    {
        return new self(sprintf('Horizon inconnu : « %s ».', $code));
    }
}
