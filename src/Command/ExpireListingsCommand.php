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
    name: 'app:expire-listings',
    description: 'Expire les annonces arrivées à terme et notifie les utilisateurs (à exécuter via CRON)'
)]
class ExpireListingsCommand extends Command
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans modifier les annonces');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Expiration des annonces');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune modification ne sera effectuée');
        }

        $now = new \DateTime();

        // Trouver les annonces actives dont la date d'expiration est passée
        $qb = $this->entityManager->createQueryBuilder();
        $expiredListings = $qb->select('l')
            ->from('App\Entity\Listing', 'l')
            ->where('l.status = :status')
            ->andWhere('l.expiresAt < :now')
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $count = count($expiredListings);

        if ($count === 0) {
            $io->success('Aucune annonce à expirer.');
            return Command::SUCCESS;
        }

        $io->info("$count annonce(s) expirée(s) trouvée(s)");

        $expired = 0;
        $notifiedUsers = 0;
        $notifiedFavorites = 0;

        foreach ($expiredListings as $listing) {
            $io->writeln(" - {$listing->getTitle()} ({$listing->getUser()->getEmail()})");

            if (!$dryRun) {
                // 1. Mettre le statut à "expired"
                $listing->setStatus('expired');
                $listing->setUpdatedAt(new \DateTime());
                $expired++;

                // 2. Notifier le propriétaire de l'annonce
                $this->notificationManager->notifyListingExpired($listing);
                $notifiedUsers++;

                // 3. Notifier tous les utilisateurs qui avaient cette annonce en favori
                $favCount = $this->notificationManager->notifyFavoriteUnavailable($listing, 'expired');
                $notifiedFavorites += $favCount;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        if ($dryRun) {
            $io->success("Mode simulation : {$count} annonce(s) auraient été expirée(s)");
        } else {
            $io->success([
                "{$expired} annonce(s) expirée(s)",
                "{$notifiedUsers} propriétaire(s) notifié(s)",
                "{$notifiedFavorites} notification(s) de favoris envoyée(s)"
            ]);
        }

        return Command::SUCCESS;
    }
}
