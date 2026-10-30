<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR représentant un taux exprimé en pourcentage.
 *
 * Décimal-safe : la valeur est stockée en interne comme un entier d'échelle
 * (le pourcentage multiplié par 10^6), jamais comme un float, afin d'éviter
 * toute dérive de précision. L'échelle décimale fixe (6 décimales) est alignée
 * sur le `DECIMAL(14,6)` des paramètres datés.
 *
 * Porteur neutre : aucune validation de plage n'est appliquée (la cohérence
 * métier 0–200 reste à la charge de l'import des paramètres).
 */
final class Rate
{
    private const SCALE = 6;

    /**
     * @param int $scaledPercentage pourcentage multiplié par 10^SCALE
     */
    private function __construct(private readonly int $scaledPercentage)
    {
    }

    /**
     * Construit un taux depuis une chaîne décimale représentant un pourcentage
     * (ex. « 82.28 » pour 82,28 %).
     */
    public static function fromPercentage(string $percentage): self
    {
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $percentage, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Pourcentage décimal invalide : "%s".',
                $percentage,
            ));
        }

        $integerPart = $matches[2];
        $fractionalPart = substr(str_pad($matches[3] ?? '', self::SCALE, '0'), 0, self::SCALE);

        $scaled = (int) ($integerPart . $fractionalPart);

        return new self($matches[1] === '-' ? -$scaled : $scaled);
    }

    /**
     * Pourcentage sous forme de chaîne décimale canonique (ex. « 82.28 »).
     */
    public function percentage(): string
    {
        return $this->format(self::SCALE);
    }

    /**
     * Ratio (pourcentage / 100) sous forme de chaîne décimale canonique
     * (ex. « 0.8228 »). Dérivé exactement du même entier d'échelle : le ratio
     * est le pourcentage avec deux décimales de plus, donc à l'échelle SCALE+2.
     */
    public function ratio(): string
    {
        return $this->format(self::SCALE + 2);
    }

    /**
     * Égalité par valeur : deux taux de même valeur sont égaux.
     */
    public function equals(self $other): bool
    {
        return $this->scaledPercentage === $other->scaledPercentage;
    }

    /**
     * Formate l'entier d'échelle en chaîne décimale à l'échelle donnée, sans
     * zéros de fin superflus.
     */
    private function format(int $scale): string
    {
        $sign = $this->scaledPercentage < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->scaledPercentage), $scale + 1, '0', STR_PAD_LEFT);

        $integerPart = substr($digits, 0, -$scale);
        $fractionalPart = rtrim(substr($digits, -$scale), '0');

        return $sign.$integerPart.('' !== $fractionalPart ? '.'.$fractionalPart : '');
    }
}
