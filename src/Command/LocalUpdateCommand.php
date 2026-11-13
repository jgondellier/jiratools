<?php

namespace App\Command;

use App\Entity\LocalApp;
use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\UpdateOptions;
use App\Repository\LocalAppRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:local-update', description: 'Met à jour une application locale : zip Git, copie dans le projet puis commandes (migrations, cache, import…)')]
class LocalUpdateCommand extends Command
{
    public function __construct(
        private readonly LocalAppRepository $repository,
        private readonly LocalUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('app', InputArgument::OPTIONAL, 'Nom ou identifiant de l\'application (vide = liste)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Met à jour toutes les applications')
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Branche, tag ou commit (défaut : celui de l\'application)')
            ->addOption('no-deploy', null, InputOption::VALUE_NONE, 'Ne télécharge pas le code : lance seulement les commandes')
            ->addOption('no-commands', null, InputOption::VALUE_NONE, 'Déploie le code sans lancer les commandes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : liste les fichiers qui seraient modifiés, sans rien écrire ni lancer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apps = $this->repository->findBy([], ['name' => 'ASC']);

        if ($input->getOption('all')) {
            $selected = $apps;
        } elseif (null !== $name = $input->getArgument('app')) {
            $selected = array_values(array_filter($apps, fn (LocalApp $a) => (string) $a->getId() === $name || 0 === strcasecmp($a->getName(), $name)));
            if ([] === $selected) {
                $io->error(sprintf('Application « %s » introuvable.', $name));
                $this->listApps($io, $apps);

                return Command::FAILURE;
            }
        } else {
            $this->listApps($io, $apps);

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($selected as $app) {
            $run = $this->updater->run($app, new UpdateOptions(
                ref: $input->getOption('ref'),
                deploy: !$input->getOption('no-deploy'),
                commands: $input->getOption('no-commands') ? [] : null,
                dryRun: (bool) $input->getOption('dry-run'),
                trigger: 'console',
            ), static function (string $text) use ($output): void {
                $output->write($text, false, OutputInterface::OUTPUT_RAW);
            });
            if ('failed' === $run->getStatus()) {
                ++$failed;
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param LocalApp[] $apps */
    private function listApps(SymfonyStyle $io, array $apps): void
    {
        if ([] === $apps) {
            $io->note('Aucune application : ajoutez-en dans « Mises à jour locales ».');

            return;
        }
        $io->table(['Id', 'Application', 'Branche', 'Projet', 'Dernière mise à jour'], array_map(fn (LocalApp $a) => [
            $a->getId(), $a->getName(), $a->getRef(), $a->getTargetDirectory(),
            $a->getLastRunAt() ? $a->getLastRunAt()->format('d/m/Y H:i').' ('.$a->getLastStatus().')' : '—',
        ], $apps));
        $io->writeln('Usage : php bin/console app:local-update <nom|id> [--ref=develop] [--dry-run]');
    }
}
