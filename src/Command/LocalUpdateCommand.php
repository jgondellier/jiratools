<?php

namespace App\Command;

use App\LocalUpdate\LocalUpdater;
use App\LocalUpdate\UpdateOptions;
use App\Repository\LocalAppRepository;
use App\Repository\LocalUpdateRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:local-update', description: 'Met à jour cette installation de Jira Tools : zip Git, copie puis commandes (composer, base de données, cache…)')]
class LocalUpdateCommand extends Command
{
    public function __construct(
        private readonly LocalAppRepository $settings,
        private readonly LocalUpdateRunRepository $runs,
        private readonly LocalUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Branche, tag ou commit (défaut : celui des paramètres)')
            ->addOption('no-deploy', null, InputOption::VALUE_NONE, 'Ne télécharge pas le code : lance seulement les commandes')
            ->addOption('no-commands', null, InputOption::VALUE_NONE, 'Déploie le code sans lancer les commandes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : liste les fichiers qui seraient modifiés, sans rien écrire ni lancer')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Exécute une mise à jour préparée depuis l\'écran (usage interne)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = static function (string $text) use ($output): void {
            $output->write($text, false, OutputInterface::OUTPUT_RAW);
        };

        if (null !== $runId = $input->getOption('run')) {
            $run = $this->runs->find((int) $runId);
            if (null === $run) {
                (new SymfonyStyle($input, $output))->error(sprintf('Exécution %s introuvable.', $runId));

                return Command::FAILURE;
            }
        } else {
            $run = $this->updater->prepare($this->settings->getSettings(), new UpdateOptions(
                ref: $input->getOption('ref'),
                deploy: !$input->getOption('no-deploy'),
                commands: $input->getOption('no-commands') ? [] : null,
                dryRun: (bool) $input->getOption('dry-run'),
                trigger: 'console',
            ));
        }

        $run = $this->updater->execute($run, $write);

        return 'failed' === $run->getStatus() ? Command::FAILURE : Command::SUCCESS;
    }
}
