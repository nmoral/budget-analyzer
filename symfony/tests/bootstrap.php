<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Bootstrap PHPUnit : provisionne la base de test dédiée `budget_test` (suffixe
 * `_test` défini dans `config/packages/doctrine.yaml`, environnement `test`) et
 * remet son schéma `public` à zéro une seule fois, avant la suite. Cela isole les
 * tests de la base de dev `budget` et rend le contrôle « schéma public sain »
 * (`DatabaseConnectionTest`) déterministe, même si `budget` est migrée.
 *
 * Tolérant à un serveur Postgres absent : les tests purs unitaires (ex.
 * Architecture) doivent tourner sans base, donc toute erreur de connexion ou de
 * provisioning est journalisée sans faire échouer le bootstrap.
 */
(static function (): void {
    try {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        // Crée `budget_test` si absente, via la logique native de Doctrine (elle
        // ouvre sa propre connexion de maintenance au serveur).
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->run(
            new ArrayInput([
                'command' => 'doctrine:database:create',
                '--if-not-exists' => true,
            ]),
            new NullOutput(),
        );

        $registry = $container->get('doctrine');
        if (!$registry instanceof ManagerRegistry) {
            return;
        }

        $connection = $registry->getConnection();
        if (!$connection instanceof Connection) {
            return;
        }

        // Remise à zéro du schéma `public` de `budget_test` : garantit un état
        // vide déterministe avant la suite (contrôle « schéma public sain »).
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->close();

        $kernel->shutdown();
    } catch (\Throwable $exception) {
        fwrite(
            STDERR,
            '[bootstrap] Provisioning de budget_test ignoré (serveur Postgres absent '
            . 'ou inaccessible) : ' . $exception->getMessage() . PHP_EOL,
        );
    }
})();
