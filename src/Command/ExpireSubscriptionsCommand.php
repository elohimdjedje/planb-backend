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
    name: 'app:expire-subscriptions',
    description: 'Expire les abonnements PRO arrivés à terme et notifie les utilisateurs (à exécuter via CRON)'
)]
class ExpireSubscriptionsCommand extends Command
{
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans modifier les abonnements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Expiration des abonnements PRO');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune modification ne sera effectuée');
        }

        $now = new \DateTimeImmutable();

        // Trouver tous les utilisateurs PRO dont l'abonnement a expiré
        $qb = $this->entityManager->createQueryBuilder();
        $expiredUsers = $qb->select('u')
            ->from('App\Entity\User', 'u')
            ->where('u.accountType = :pro')
            ->andWhere('u.subscriptionExpiresAt IS NOT NULL')
            ->andWhere('u.subscriptionExpiresAt < :now')
            ->andWhere('u.isLifetimePro = false')  // Exclure les PRO à vie
            ->setParameter('pro', 'PRO')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $count = count($expiredUsers);

        if ($count === 0) {
            $io->success('Aucun abonnement expiré trouvé.');
            return Command::SUCCESS;
        }

        $io->info("$count abonnement(s) PRO expiré(s) trouvé(s)");

        $expired = 0;
        $notified = 0;

        // Repasser chaque utilisateur en FREE
        foreach ($expiredUsers as $user) {
            $io->writeln(" - {$user->getEmail()} : PRO → FREE");

            if (!$dryRun) {
                $user->setAccountType('FREE');
                $user->setSubscriptionExpiresAt(null);

                // Mettre à jour l'abonnement
                $subscription = $user->getSubscription();
                if ($subscription) {
                    $subscription->setStatus('expired');
                    $subscription->setUpdatedAt(new \DateTimeImmutable());
                }

                $expired++;

                // Notifier l'utilisateur
                $this->notificationManager->notifySubscriptionExpired($user);
                $notified++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        if ($dryRun) {
            $io->success("Mode simulation : {$count} utilisateur(s) auraient été repassé(s) en FREE");
        } else {
            $io->success([
                "{$expired} utilisateur(s) repassé(s) en FREE",
                "{$notified} notification(s) envoyée(s)"
            ]);
        }

        return Command::SUCCESS;
    }
}
