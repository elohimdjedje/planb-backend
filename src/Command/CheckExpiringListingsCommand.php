<?php

namespace App\Command;

use App\Repository\ListingRepository;
use App\Service\NotificationManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-expiring-listings',
    description: 'Vérifie les annonces qui expirent bientôt et envoie des notifications (à exécuter via CRON)'
)]
class CheckExpiringListingsCommand extends Command
{
    public function __construct(
        private ListingRepository $listingRepository,
        private NotificationManagerService $notificationManager,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans envoyer les notifications')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Jours avant expiration', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $days = (int) $input->getOption('days');

        $io->title('Vérification des annonces expirant bientôt');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune notification ne sera envoyée');
        }

        $now = new \DateTime();
        $targetDate = (new \DateTime())->modify("+{$days} days");

        // Trouver les annonces actives qui expirent dans X jours
        $qb = $this->entityManager->createQueryBuilder();
        $expiringListings = $qb->select('l')
            ->from('App\Entity\Listing', 'l')
            ->join('l.user', 'u')
            ->where('l.status = :status')
            ->andWhere('l.expiresAt BETWEEN :now AND :targetDate')
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->setParameter('targetDate', $targetDate)
            ->getQuery()
            ->getResult();

        $count = count($expiringListings);

        if ($count === 0) {
            $io->success('Aucune annonce expirant bientôt trouvée.');
            return Command::SUCCESS;
        }

        $io->info("$count annonce(s) trouvée(s) expirant dans les $days prochains jours");

        $notified = 0;

        foreach ($expiringListings as $listing) {
            $daysRemaining = $now->diff($listing->getExpiresAt())->days;
            
            $io->writeln(" - {$listing->getTitle()} (expire dans {$daysRemaining} jours) - {$listing->getUser()->getEmail()}");

            if (!$dryRun) {
                $this->notificationManager->notifyListingExpiringSoon($listing, $daysRemaining);
                $notified++;
            }
        }

        if ($dryRun) {
            $io->success("Mode simulation : {$count} notification(s) auraient été envoyée(s)");
        } else {
            $io->success("{$notified} notification(s) envoyée(s) avec succès !");
        }

        return Command::SUCCESS;
    }
}
