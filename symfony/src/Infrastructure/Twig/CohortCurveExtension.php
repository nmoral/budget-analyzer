<?php

declare(strict_types=1);

namespace App\Infrastructure\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Courbe de cohorte (#20, C5) : géométrie d'un SVG inline généré SERVER-SIDE à partir
 * de la série `perYear` de l'horizon cohorte (année → coût total). Aucune librairie JS
 * de graphe ; la fonction renvoie les coordonnées d'une polyline (un point par année)
 * et les extrêmes (min/max des totaux), de sorte que la courbe reste cohérente avec le
 * tableau cohorte (même nombre de points, mêmes extrêmes) et déterministe/testable.
 *
 * Les totaux sont des chaînes décimales canoniques (`Amount::toString`), comparées de
 * façon décimale-sûre (bccomp) pour les extrêmes ; seule la géométrie (positions des
 * points, pure présentation) passe par le float.
 */
final class CohortCurveExtension extends AbstractExtension
{
    private const int WIDTH = 300;
    private const int HEIGHT = 100;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cohort_curve', $this->curve(...)),
        ];
    }

    /**
     * @param list<array<string, numeric-string>> $perYear série année → coûts (clé `total` = chaîne décimale canonique)
     *
     * @return array{points: string, min: numeric-string, max: numeric-string, count: int, width: int, height: int}
     */
    public function curve(array $perYear): array
    {
        $totals = array_map(
            static fn (array $year): string => $year['total'],
            $perYear,
        );

        $count = \count($totals);
        if ($count === 0) {
            return ['points' => '', 'min' => '0', 'max' => '0', 'count' => 0, 'width' => self::WIDTH, 'height' => self::HEIGHT];
        }

        $min = $totals[0];
        $max = $totals[0];
        foreach ($totals as $total) {
            if (bccomp($total, $min, 6) < 0) {
                $min = $total;
            }
            if (bccomp($total, $max, 6) > 0) {
                $max = $total;
            }
        }

        $minFloat = (float) $min;
        $range = (float) $max - $minFloat;

        $coordinates = [];
        foreach ($totals as $index => $total) {
            $x = $count === 1 ? 0.0 : $index * (self::WIDTH / ($count - 1));
            // SVG : l'origine est en haut à gauche ; le plus grand montant est tracé en
            // haut (y = 0), le plus petit en bas (y = HEIGHT). Série plate → ligne médiane.
            $y = $range === 0.0
                ? self::HEIGHT / 2
                : self::HEIGHT - (((float) $total - $minFloat) / $range) * self::HEIGHT;
            $coordinates[] = sprintf('%s,%s', $this->format($x), $this->format($y));
        }

        return [
            'points' => implode(' ', $coordinates),
            'min' => $min,
            'max' => $max,
            'count' => $count,
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
        ];
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
