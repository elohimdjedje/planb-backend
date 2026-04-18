<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-expiring-subscriptions',
    description: 'Vérifie les abonnements PRO qui expirent bientôt et envoie des notifications (à exécuter via CRON)'
)]
class CheckExpiringSubscriptionsCommand extends Command
{
    // Jours avant expiration où envoyer des notifications
    private const REMINDER_DAYS = [30, 15, 7, 3, 1, 0];

    public function __construct(
        private UserRepository $userRepository,
        private NotificationManagerService $notificationManager,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans envoyer les notifications');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Vérification des abonnements PRO expirant bientôt');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune notification ne sera envoyée');
        }

        $now = new \DateTime();
        $notificationsSent = 0;

        foreach (self::REMINDER_DAYS as $days) {
            $io->section("Recherche des abonnements expirant dans {$days} jour(s)");

            // Calculer la date cible (aujourd'hui + X jours)
            $targetStart = (new \DateTime())->modify("+{$days} days")->setTime(0, 0, 0);
            $targetEnd = (new \DateTime())->modify("+{$days} days")->setTime(23, 59, 59);

            // Trouver les utilisateurs PRO dont l'abonnement expire ce jour-là
            $qb = $this->entityManager->createQueryBuilder();
            $users = $qb->select('u')
                ->from('App\Entity\User', 'u')
                ->where('u.accountType = :pro')
                ->andWhere('u.subscriptionExpiresAt BETWEEN :startDate AND :endDate')
                ->andWhere('u.isLifetimePro = false')
                ->setParameter('pro', 'PRO')
                ->setParameter('startDate', $targetStart)
                ->setParameter('endDate', $targetEnd)
                ->getQuery()
                ->getResult();

            $count = count($users);

            if ($count === 0) {
                $io->writeln(" → Aucun abonnement trouvé");
                continue;
            }

            $io->info("{$count} abonnement(s) expirant dans {$days} jour(s)");

            foreach ($users as $user) {
                $expiresAt = $user->getSubscriptionExpiresAt();
                $io->writeln("   - {$user->getEmail()} (expire le {$expiresAt->format('d/m/Y')})");

                if (!$dryRun) {
                    if ($days === 0) {
                        // Le jour même - notification urgente
                        $this->notificationManager->notifySubscriptionExpiring($user, 0);
                    } else {
                        $this->notificationManager->notifySubscriptionExpiring($user, $days);
                    }
                    $notificationsSent++;
                }
            }
        }

        if ($dryRun) {
            $io->success("Mode simulation terminé");
        } else {
            $io->success("{$notificationsSent} notification(s) envoyée(s) avec succès !");
        }

        return Command::SUCCESS;
    }
}
