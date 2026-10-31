<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : une entrée datée réellement utilisée par le calcul,
 * décrite pour la transparence (#65). Porte la clé du paramètre, sa valeur et son
 * unité telles que lues, et la date d'effet (début de validité) de la valeur.
 *
 * Le domaine ne connaît NI `Source` NI la persistance : la correspondance entrée →
 * source est reconstituée hors du domaine (infrastructure) à partir de la clé et de
 * la date d'effet.
 */
final class CostInput
{
    public function __construct(
        private readonly string $key,
        private readonly string $value,
        private readonly string $unit,
        private readonly \DateTimeImmutable $effectiveDate,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function effectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    /**
     * Égalité par valeur sur les quatre composantes.
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key
            && $this->value === $other->value
            && $this->unit === $other->unit
            && $this->effectiveDate->format('Y-m-d') === $other->effectiveDate->format('Y-m-d');
    }
}
