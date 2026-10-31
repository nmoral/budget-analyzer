<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

/**
 * Résultat de scénario accompagné de son origine de cache (#19, J3) : `hit` à vrai
 * quand il provient du pool, faux quand il vient d'être calculé. Sert à poser
 * l'en-tête `X-Cache`.
 */
final class CachedScenarioResult
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        private readonly array $result,
        private readonly bool $hit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return $this->result;
    }

    public function hit(): bool
    {
        return $this->hit;
    }
}
