<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR représentant un montant décimal.
 *
 * Décimal-safe : la valeur est une chaîne décimale canonique à l'échelle fixe
 * de 6 décimales (alignée sur le `DECIMAL(14,6)` des paramètres datés), et tous
 * les calculs passent par bcmath ; aucun float n'intervient.
 *
 * Règles d'arrondi : un produit est arrondi à 6 décimales half-up (demi
 * s'éloignant de zéro) ; une somme est exacte. Une entrée portant plus de
 * 6 décimales est arrondie selon la même règle.
 */
final class Amount
{
    private const SCALE = 6;

    /**
     * Échelle de calcul d'un produit par un taux : le ratio d'un `Rate` porte
     * deux décimales de plus que son pourcentage (SCALE + 2), d'où un produit
     * exact à l'échelle SCALE + SCALE + 2 avant arrondi.
     */
    private const RATE_PRODUCT_SCALE = self::SCALE + self::SCALE + 2;

    /**
     * @param numeric-string $value chaîne décimale canonique à l'échelle SCALE
     */
    private function __construct(private readonly string $value)
    {
    }

    /**
     * Construit un montant depuis une chaîne décimale à point (ex. « 23333.993 »).
     */
    public static function fromString(string $value): self
    {
        self::assertDecimal($value);

        return self::rounded($value);
    }

    /**
     * Produit par un taux, arrondi à 6 décimales half-up.
     */
    public function multipliedByRate(Rate $rate): self
    {
        /** @var numeric-string $ratio */
        $ratio = $rate->ratio();

        return self::rounded(bcmul($this->value, $ratio, self::RATE_PRODUCT_SCALE));
    }

    /**
     * Produit par un indice entier (ex. indice majoré), exact.
     */
    public function multipliedByIndex(int $index): self
    {
        return new self(bcmul($this->value, (string) $index, self::SCALE));
    }

    /**
     * Somme exacte, sans arrondi supplémentaire.
     */
    public function plus(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    /**
     * Égalité par valeur : deux montants de même valeur sont égaux.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Représentation canonique à 6 décimales (ex. « 23333.993000 »).
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Exige une chaîne décimale à point, sans exposant ni signe « + ».
     *
     * @phpstan-assert numeric-string $value
     */
    private static function assertDecimal(string $value): void
    {
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Montant décimal invalide : "%s".',
                $value,
            ));
        }
    }

    /**
     * @param numeric-string $value
     */
    private static function rounded(string $value): self
    {
        return new self(bcadd(bcround($value, self::SCALE), '0', self::SCALE));
    }
}
