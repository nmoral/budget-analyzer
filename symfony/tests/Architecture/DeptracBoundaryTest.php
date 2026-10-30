<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Frontière de la couche de domaine constatée par Deptrac (et non par une
 * simple convention) : App\Domain reste du PHP pur, sans dépendre de Doctrine\*
 * ni de App\Infrastructure\*.
 *
 * - C1.1 : sur le domaine conforme, `deptrac` sort en 0.
 * - C1.2 : si un fichier de src/Domain ajoute `use Doctrine\ORM\…`, `deptrac`
 *   sort en != 0 et nomme la dépendance interdite (CI rouge). La violation est
 *   introduite dans un fichier temporaire supprimé en fin de test : la frontière
 *   est démontrée sans laisser de violation permanente dans src/.
 */
final class DeptracBoundaryTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../..';

    public function testDeptracPassesOnConformingDomain(): void
    {
        [$exitCode, $output] = $this->runDeptrac();

        self::assertSame(
            0,
            $exitCode,
            "deptrac should report no violation on the conforming domain:\n" . $output,
        );
    }

    public function testDeptracFailsWhenDomainDependsOnDoctrine(): void
    {
        $probe = self::PROJECT_DIR . '/src/Domain/DeptracDoctrineProbe.php';
        file_put_contents($probe, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Domain;

            use Doctrine\ORM\EntityManagerInterface;

            final class DeptracDoctrineProbe
            {
                public function __construct(private readonly EntityManagerInterface $em)
                {
                }
            }
            PHP);

        try {
            [$exitCode, $output] = $this->runDeptrac();
        } finally {
            @unlink($probe);
        }

        self::assertNotSame(
            0,
            $exitCode,
            "deptrac should fail when App\\Domain depends on Doctrine:\n" . $output,
        );
        self::assertStringContainsString(
            'Doctrine',
            $output,
            'deptrac output should name the forbidden Doctrine dependency.',
        );
        self::assertStringContainsString(
            'App\\Domain\\DeptracDoctrineProbe',
            $output,
            'deptrac output should name the offending domain class.',
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runDeptrac(): array
    {
        $root = realpath(self::PROJECT_DIR);
        self::assertIsString($root, 'Unable to resolve the project root.');

        $binary = $root . '/vendor/bin/deptrac';
        self::assertFileExists($binary, 'deptrac must be installed (composer require --dev deptrac/deptrac).');
        self::assertFileExists($root . '/deptrac.yaml', 'deptrac.yaml config must exist.');

        $command = sprintf(
            '%s analyse --no-progress --no-cache --config-file=%s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($root . '/deptrac.yaml'),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
