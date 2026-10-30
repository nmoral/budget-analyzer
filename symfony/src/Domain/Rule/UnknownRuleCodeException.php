<?php

declare(strict_types=1);

namespace App\Domain\Rule;

/**
 * Exception de domaine levée lorsqu'un code de règle référencé n'a aucune
 * implémentation enregistrée dans sa famille. Le message nomme la famille et le
 * code fautif (pas de comportement par défaut silencieux).
 */
final class UnknownRuleCodeException extends \DomainException
{
    public static function forFamily(string $family, string $code): self
    {
        return new self(sprintf(
            'Aucune implémentation de règle pour la famille "%s" et le code "%s".',
            $family,
            $code,
        ));
    }
}
