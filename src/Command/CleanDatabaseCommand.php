<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'app:clean-database',
    description: 'Nettoie la base de données (supprime tous les utilisateurs et données associées)',
)]
class CleanDatabaseCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force la suppression sans confirmation')
            ->setHelp('Cette commande supprime TOUS les utilisateurs et leurs données associées de la base de données.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->warning([
            '⚠️  ATTENTION - OPÉRATION DANGEREUSE',
            'Cette commande va supprimer TOUTES les données suivantes :',
            '- Tous les utilisateurs',
            '- Toutes les annonces (listings)',
            '- Tous les paiements',
            '- Toutes les commandes (orders)',
            '- Toutes les opérations',
            '- Tous les abonnements',
            '',
            'Cette action est IRRÉVERSIBLE !'
        ]);

        // Demander confirmation si --force n'est pas utilisé
        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Êtes-vous ABSOLUMENT SÛR de vouloir continuer ? (tapez "oui" pour confirmer) : ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $io->info('Opération annulée.');
                return Command::SUCCESS;
            }
        }

        $io->section('🗑️  Nettoyage de la base de données...');

        try {
            // Désactiver les contraintes de clés étrangères temporairement
            $connection = $this->entityManager->getConnection();
            
            $io->text('Désactivation des contraintes de clés étrangères...');
            $connection->executeStatement('SET session_replication_role = replica;');

            // Supprimer dans l'ordre pour respecter les dépendances
            $tables = [
                'operations',
                'orders',
                'payments',
                'subscriptions',
                'listings',
                'users'
            ];

            $totalDeleted = 0;

            foreach ($tables as $table) {
                $io->text("Suppression de la table '$table'...");
                
                // Compter avant suppression
                $count = $connection->fetchOne("SELECT COUNT(*) FROM $table");
                
                // Supprimer
                $connection->executeStatement("DELETE FROM $table");
                
                // Réinitialiser les séquences d'auto-incrémentation
                $connection->executeStatement("ALTER SEQUENCE {$table}_id_seq RESTART WITH 1");
                
                $totalDeleted += $count;
                $io->text("✅ $count ligne(s) supprimée(s) de '$table'");
            }

            // Réactiver les contraintes de clés étrangères
            $io->text('Réactivation des contraintes de clés étrangères...');
            $connection->executeStatement('SET session_replication_role = DEFAULT;');

            $io->newLine();
            $io->success([
                "✅ Base de données nettoyée avec succès !",
                "Total : $totalDeleted ligne(s) supprimée(s)",
                "Les séquences d'auto-incrémentation ont été réinitialisées."
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error([
                '❌ Erreur lors du nettoyage de la base de données',
                $e->getMessage()
            ]);

            // Essayer de réactiver les contraintes en cas d'erreur
            try {
                $connection->executeStatement('SET session_replication_role = DEFAULT;');
            } catch (\Exception $e2) {
                // Ignorer
            }

            return Command::FAILURE;
        }
    }
}
