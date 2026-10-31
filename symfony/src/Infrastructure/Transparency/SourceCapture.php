<?php

declare(strict_types=1);

namespace App\Infrastructure\Transparency;

use App\Infrastructure\Entity\Source;

/**
 * Pont de capture entrée → source (#65, J2), côté infrastructure.
 *
 * Le domaine expose une trace de calcul PURE : par terme, une formule et la liste
 * des entrées datées utilisées (clé + date d'effet), sans jamais connaître `Source`
 * ni Doctrine. Les adapters datés, eux, savent d'où vient chaque valeur : à chaque
 * lecture ils enregistrent ici la correspondance (clé, date d'effet) → `Source`
 * enrichie (#65 J1). La sérialisation HTTP (`detail=full`) consommera ce registre
 * en J3 ; en J2 on ne fait que le construire.
 *
 * Service à état, à portée d'une requête : `reset()` le vide avant un nouveau calcul.
 */
final class SourceCapture
{
    /** @var array<string, Source> source indexée par la clé composite (clé, date d'effet) */
    private array $sources = [];

    public function record(string $key, \DateTimeImmutable $effectiveDate, Source $source): void
    {
        $this->sources[$this->compositeKey($key, $effectiveDate)] = $source;
    }

    public function sourceFor(string $key, \DateTimeImmutable $effectiveDate): ?Source
    {
        return $this->sources[$this->compositeKey($key, $effectiveDate)] ?? null;
    }

    public function reset(): void
    {
        $this->sources = [];
    }

    private function compositeKey(string $key, \DateTimeImmutable $effectiveDate): string
    {
        return $key . '@' . $effectiveDate->format('Y-m-d');
    }
}
