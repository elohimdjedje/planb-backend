<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-notifications',
    description: 'Exécute toutes les vérifications de notifications (abonnements, annonces, nettoyage) - à exécuter via CRON quotidien'
)]
class ProcessNotificationsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mode simulation pour toutes les commandes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $application = $this->getApplication();

        if (!$application) {
            $io->error('Application non disponible');
            return Command::FAILURE;
        }

        $io->title('🔔 Traitement des notifications Plan B');
        $io->writeln('Date/Heure: ' . (new \DateTime())->format('d/m/Y H:i:s'));
        $io->newLine();

        if ($dryRun) {
            $io->caution('Mode simulation activé pour toutes les commandes');
        }

        $commands = [
            [
                'name' => 'app:check-expiring-subscriptions',
                'description' => 'Vérification des abonnements PRO expirant bientôt',
                'options' => $dryRun ? ['--dry-run' => true] : []
            ],
            [
                'name' => 'app:expire-subscriptions',
                'description' => 'Expiration des abonnements PRO arrivés à terme',
                'options' => $dryRun ? ['--dry-run' => true] : []
            ],
            [
                'name' => 'app:check-expiring-listings',
                'description' => 'Vérification des annonces expirant bientôt (3 jours)',
                'options' => array_merge(['--days' => '3'], $dryRun ? ['--dry-run' => true] : [])
            ],
            [
                'name' => 'app:expire-listings',
                'description' => 'Expiration des annonces arrivées à terme',
                'options' => $dryRun ? ['--dry-run' => true] : []
            ],
            [
                'name' => 'app:cleanup-notifications',
                'description' => 'Nettoyage des anciennes notifications',
                'options' => array_merge(['--days' => '30'], $dryRun ? ['--dry-run' => true] : [])
            ],
        ];

        $results = [];
        $hasErrors = false;

        foreach ($commands as $cmdConfig) {
            $io->section("📋 {$cmdConfig['description']}");
            $io->writeln("Commande: {$cmdConfig['name']}");
            $io->newLine();

            try {
                $command = $application->find($cmdConfig['name']);
                $arguments = array_merge(['command' => $cmdConfig['name']], $cmdConfig['options']);
                $commandInput = new ArrayInput($arguments);
                
                $returnCode = $command->run($commandInput, $output);
                
                $results[$cmdConfig['name']] = [
                    'success' => $returnCode === Command::SUCCESS,
                    'code' => $returnCode
                ];

                if ($returnCode !== Command::SUCCESS) {
                    $hasErrors = true;
                }
            } catch (\Exception $e) {
                $io->error("Erreur: {$e->getMessage()}");
                $results[$cmdConfig['name']] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $hasErrors = true;
            }

            $io->newLine();
        }

        // Résumé
        $io->section('📊 Résumé de l\'exécution');

        foreach ($results as $cmdName => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $io->writeln("  {$status} {$cmdName}");
        }

        $io->newLine();

        if ($hasErrors) {
            $io->warning('Certaines commandes ont rencontré des erreurs.');
            return Command::FAILURE;
        }

        $io->success('Toutes les vérifications ont été effectuées avec succès !');
        return Command::SUCCESS;
    }
}
