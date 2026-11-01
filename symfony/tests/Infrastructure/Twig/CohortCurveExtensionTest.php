<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Twig;

use App\Infrastructure\Twig\CohortCurveExtension;
use PHPUnit\Framework\TestCase;

/**
 * Géométrie de la courbe de cohorte (#20, C5) : un point par année, extrêmes = min/max
 * des totaux, de façon déterministe et cohérente avec le tableau cohorte.
 */
final class CohortCurveExtensionTest extends TestCase
{
    /**
     * Un point de polyline par année de la série, dans l'ordre.
     */
    public function testOnePointPerYear(): void
    {
        $curve = (new CohortCurveExtension())->curve($this->series(['10.000000', '30.000000', '20.000000']));

        self::assertSame(3, $curve['count']);
        self::assertCount(3, explode(' ', trim($curve['points'])));
    }

    /**
     * Les extrêmes exposés (data-min/data-max) sont le plus petit et le plus grand
     * total de la série, repris verbatim (chaînes décimales canoniques).
     */
    public function testExtremesMatchSeries(): void
    {
        $curve = (new CohortCurveExtension())->curve($this->series(['30.000000', '10.000000', '45.500000', '20.000000']));

        self::assertSame('10.000000', $curve['min']);
        self::assertSame('45.500000', $curve['max']);
    }

    /**
     * Série plate (max == min) : courbe tracée sur la ligne médiane, sans division par
     * zéro, et toujours un point par année.
     */
    public function testFlatSeriesStaysOnMidline(): void
    {
        $curve = (new CohortCurveExtension())->curve($this->series(['12.000000', '12.000000']));

        self::assertSame('12.000000', $curve['min']);
        self::assertSame('12.000000', $curve['max']);
        self::assertCount(2, explode(' ', trim($curve['points'])));
        self::assertStringContainsString('50', $curve['points']);
    }

    /**
     * @param list<numeric-string> $totals
     *
     * @return list<array<string, numeric-string>>
     */
    private function series(array $totals): array
    {
        return array_map(
            static fn (string $total): array => [
                'treatmentAndPrimes' => '0',
                'retirement' => '0',
                'otherContributions' => '0',
                'rafp' => '0',
                'total' => $total,
            ],
            $totals,
        );
    }
}
