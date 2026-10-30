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
        return self::fromDecimal($percentage, self::SCALE, 'Pourcentage');
    }

    /**
     * Construit un taux depuis une chaîne décimale représentant un ratio
     * (ex. « 0.21 » pour 21 %). Le ratio porte deux décimales de plus que le
     * pourcentage : il est lu à l'échelle SCALE+2.
     */
    public static function fromRatio(string $ratio): self
    {
        return self::fromDecimal($ratio, self::SCALE + 2, 'Ratio');
    }

    /**
     * Convertit une chaîne décimale en entier d'échelle, en lisant `$scale`
     * décimales (les décimales au-delà sont tronquées).
     */
    private static function fromDecimal(string $decimal, int $scale, string $label): self
    {
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $decimal, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s décimal invalide : "%s".',
                $label,
                $decimal,
            ));
        }

        $integerPart = $matches[2];
        $fractionalPart = substr(str_pad($matches[3] ?? '', $scale, '0'), 0, $scale);

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
