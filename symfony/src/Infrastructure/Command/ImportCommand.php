<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Infrastructure\Import\Importer;
use App\Infrastructure\Import\ImportException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande impérative `app:import <commit>` (C1).
 *
 * Importe l'état présent du dépôt de données et l'étiquette avec le commit fourni
 * (argument opaque, sans checkout — décision Q1). Import atomique : exit 0 au succès,
 * exit ≠ 0 avec message et rollback total au moindre échec (C2).
 */
#[AsCommand(
    name: 'app:import',
    description: 'Importe le dépôt de données vers Postgres et crée un jeu de paramètres versionné.',
)]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly Importer $importer,
        private readonly string $defaultDataDir,
        private readonly CacheItemPoolInterface $resultCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'commit',
                InputArgument::REQUIRED,
                'Référence du commit du dépôt de données à étiqueter (version_id).',
            )
            ->addOption(
                'data-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Racine du dépôt de données à importer.',
                $this->defaultDataDir,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $commitArgument = $input->getArgument('commit');
        $commit = is_string($commitArgument) ? $commitArgument : '';

        $dataDirOption = $input->getOption('data-dir');
        $dataDir = is_string($dataDirOption) ? $dataDirOption : $this->defaultDataDir;

        try {
            $this->importer->import($commit, $dataDir);
        } catch (ImportException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // Les résultats mis en cache dépendent des paramètres importés : on purge le
        // pool pour qu'ils soient recalculés contre le nouvel état (jamais de cache
        // périmé, y compris après un ré-import idempotent du même commit).
        $this->resultCache->clear();

        $io->success(sprintf('Import du commit %s terminé.', $commit));

        return Command::SUCCESS;
    }
}
