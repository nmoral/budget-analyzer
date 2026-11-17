<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Issue #62 — C5 / C5.1 : aucun code de production hors `Importer` n'instancie les
 * entités `Profile`, `RetirementScheme` ou `Funder` ; elles n'entrent en base que par
 * la chaîne d'import (`app:import`).
 *
 * Le contrôle analyse les jetons PHP de `src/` : chaque `new X` est résolu en nom
 * complet (espace de noms et `use` du fichier), ce qui distingue l'entité Doctrine
 * `App\Infrastructure\Entity\Profile` du profil de domaine `App\Domain\Profile\Profile`.
 */
final class ReferentialWriteBoundaryTest extends TestCase
{
    private const string SRC_DIR = __DIR__ . '/../../src';

    private const string ALLOWED_FILE = 'Infrastructure/Import/Importer.php';

    /** @var list<string> */
    private const array GUARDED_ENTITIES = [
        'App\Infrastructure\Entity\Profile',
        'App\Infrastructure\Entity\RetirementScheme',
        'App\Infrastructure\Entity\Funder',
    ];

    /** #62 C5.1 — code de production : aucune instanciation hors `Importer`. */
    public function testNoProductionCodeOutsideImporterInstantiatesGuardedEntities(): void
    {
        $violations = [];
        foreach ($this->productionFiles() as $relative => $path) {
            if ($relative === self::ALLOWED_FILE) {
                continue;
            }
            $code = file_get_contents($path);
            self::assertIsString($code, $path);
            foreach ($this->guardedInstantiations($code) as $class) {
                $violations[] = sprintf('%s : new %s', $relative, $class);
            }
        }

        self::assertSame([], $violations, 'Seul l\'Importer peut créer Profile, RetirementScheme ou Funder.');
    }

    /** #62 C5.1 — l'`Importer` reste la voie d'écriture : le contrôle l'y voit bien. */
    public function testImporterIsTheOnlyAllowedWriter(): void
    {
        $code = file_get_contents(self::SRC_DIR . '/' . self::ALLOWED_FILE);
        self::assertIsString($code);

        self::assertEqualsCanonicalizing(self::GUARDED_ENTITIES, array_values(array_unique($this->guardedInstantiations($code))));
    }

    /**
     * #62 C5.1 — le contrôle échoue si l'on ajoute une instanciation ailleurs, quelle
     * que soit la façon de nommer la classe.
     */
    public function testDetectsEveryWayOfNamingAGuardedEntity(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Infrastructure\Controller;

            use App\Infrastructure\Entity\Profile;
            use App\Infrastructure\Entity\Funder as Financeur;
            use App\Infrastructure\Entity;

            final class Sneaky
            {
                public function run(): void
                {
                    $a = new Profile();
                    $b = new Financeur();
                    $c = new \App\Infrastructure\Entity\RetirementScheme();
                    $d = new Entity\Funder();
                }
            }
            PHP;

        self::assertSame(
            [
                'App\Infrastructure\Entity\Profile',
                'App\Infrastructure\Entity\Funder',
                'App\Infrastructure\Entity\RetirementScheme',
                'App\Infrastructure\Entity\Funder',
            ],
            $this->guardedInstantiations($code),
        );
    }

    /** #62 C5.1 — le profil de domaine et les autres entités ne sont pas visés. */
    public function testIgnoresDomainProfileAndOtherEntities(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\Infrastructure\Profile;

            use App\Domain\Profile\Profile;
            use App\Infrastructure\Entity\Scenario;

            final class Factory
            {
                public function run(): void
                {
                    $a = new Profile('CAS_CIVIL', 'IFSE', 'ETAT');
                    $b = new Scenario();
                    $c = new class () {};
                    $d = new static();
                }
            }
            PHP;

        self::assertSame([], $this->guardedInstantiations($code));
    }

    /**
     * Noms complets des entités gardées instanciées par `new` dans `$code`, dans l'ordre.
     *
     * @return list<string>
     */
    private function guardedInstantiations(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $namespace = '';
        $aliases = [];
        $depth = 0;
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            } elseif ($token->text === '}') {
                --$depth;
            } elseif ($token->is(T_NAMESPACE) && $depth === 0) {
                $namespace = $this->statement($tokens, $i);
            } elseif ($token->is(T_USE) && $depth === 0) {
                $aliases = array_merge($aliases, $this->parseUse($this->statement($tokens, $i)));
            } elseif ($token->is(T_NEW) && isset($tokens[$i + 1])) {
                $class = $this->resolve($tokens[$i + 1], $namespace, $aliases);
                if ($class !== null && in_array($class, self::GUARDED_ENTITIES, true)) {
                    $found[] = $class;
                }
            }
        }

        return $found;
    }

    /**
     * Texte de l'instruction qui suit `$tokens[$i]` jusqu'au `;` (curseur avancé).
     *
     * @param list<\PhpToken> $tokens
     */
    private function statement(array $tokens, int &$i): string
    {
        $texts = [];
        while (isset($tokens[++$i]) && $tokens[$i]->text !== ';') {
            $texts[] = $tokens[$i]->text;
        }

        $text = (string) preg_replace('/\s*([\\\\{},])\s*/', '$1', implode(' ', $texts));

        return trim($text);
    }

    /**
     * `use` d'import de classes (simple, alias, liste, groupe) → alias => nom complet.
     *
     * @return array<string, string>
     */
    private function parseUse(string $statement): array
    {
        if (preg_match('/^(function|const)\b/', $statement) === 1) {
            return [];
        }

        $entries = [];
        if (preg_match('/^(.*)\\\\\{(.*)\}$/s', $statement, $group) === 1) {
            foreach (explode(',', $group[2]) as $part) {
                $entries[] = $group[1] . '\\' . trim($part);
            }
        } else {
            $entries = array_map('trim', explode(',', $statement));
        }

        $aliases = [];
        foreach ($entries as $entry) {
            $parts = preg_split('/\s+as\s+/i', trim($entry));
            self::assertIsArray($parts);
            $name = ltrim($parts[0], '\\');
            $segments = explode('\\', $name);
            $aliases[$parts[1] ?? end($segments)] = $name;
        }

        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     */
    private function resolve(\PhpToken $token, string $namespace, array $aliases): ?string
    {
        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($token->text, '\\');
        }
        if (!$token->is([T_STRING, T_NAME_QUALIFIED])) {
            return null;
        }

        $segments = explode('\\', $token->text);
        $first = array_shift($segments);
        if (isset($aliases[$first])) {
            return implode('\\', [$aliases[$first], ...$segments]);
        }

        return ltrim($namespace . '\\' . $token->text, '\\');
    }

    /**
     * @return iterable<string, string> chemin relatif à `src/` => chemin absolu
     */
    private function productionFiles(): iterable
    {
        $root = realpath(self::SRC_DIR);
        self::assertIsString($root);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => $file->getPathname();
            }
        }
    }
}
