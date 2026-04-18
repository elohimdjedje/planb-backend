<?php

namespace App\Command;

use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-notifications',
    description: 'Nettoie les anciennes notifications (à exécuter via CRON)'
)]
class CleanupNotificationsCommand extends Command
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Supprimer les notifications lues de plus de X jours', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');

        $io->title('Nettoyage des notifications');

        if ($dryRun) {
            $io->note('Mode simulation activé - aucune suppression ne sera effectuée');
        }

        // 1. Supprimer les notifications expirées
        $io->section('Suppression des notifications expirées');
        
        if ($dryRun) {
            $expiredCount = $this->entityManager->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from('App\Entity\Notification', 'n')
                ->where('n.expiresAt IS NOT NULL')
                ->andWhere('n.expiresAt < :now')
                ->setParameter('now', new \DateTime())
                ->getQuery()
                ->getSingleScalarResult();
            $io->writeln(" → {$expiredCount} notification(s) expirée(s) seraient supprimée(s)");
        } else {
            $expiredCount = $this->notificationRepository->deleteExpired();
            $io->writeln(" → {$expiredCount} notification(s) expirée(s) supprimée(s)");
        }

        // 2. Supprimer les anciennes notifications lues
        $io->section("Suppression des notifications lues de plus de {$days} jours");
        
        $date = new \DateTime();
        $date->modify("-{$days} days");

        if ($dryRun) {
            $oldReadCount = $this->entityManager->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from('App\Entity\Notification', 'n')
                ->where('n.status = :status')
                ->andWhere('n.readAt < :date')
                ->setParameter('status', 'read')
                ->setParameter('date', $date)
                ->getQuery()
                ->getSingleScalarResult();
            $io->writeln(" → {$oldReadCount} notification(s) ancienne(s) lue(s) seraient supprimée(s)");
        } else {
            $oldReadCount = $this->notificationRepository->deleteOldRead($days);
            $io->writeln(" → {$oldReadCount} notification(s) ancienne(s) lue(s) supprimée(s)");
        }

        // 3. Supprimer les notifications archivées de plus de 60 jours
        $io->section('Suppression des notifications archivées anciennes');
        
        $archiveDate = new \DateTime();
        $archiveDate->modify("-60 days");

        if ($dryRun) {
            $archivedCount = $this->entityManager->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from('App\Entity\Notification', 'n')
                ->where('n.status = :status')
                ->andWhere('n.createdAt < :date')
                ->setParameter('status', 'archived')
                ->setParameter('date', $archiveDate)
                ->getQuery()
                ->getSingleScalarResult();
            $io->writeln(" → {$archivedCount} notification(s) archivée(s) seraient supprimée(s)");
        } else {
            $archivedCount = $this->entityManager->createQueryBuilder()
                ->delete('App\Entity\Notification', 'n')
                ->where('n.status = :status')
                ->andWhere('n.createdAt < :date')
                ->setParameter('status', 'archived')
                ->setParameter('date', $archiveDate)
                ->getQuery()
                ->execute();
            $io->writeln(" → {$archivedCount} notification(s) archivée(s) supprimée(s)");
        }

        $totalDeleted = $expiredCount + $oldReadCount + $archivedCount;

        if ($dryRun) {
            $io->success("Mode simulation : {$totalDeleted} notification(s) auraient été supprimée(s)");
        } else {
            $io->success("{$totalDeleted} notification(s) supprimée(s) au total !");
        }

        return Command::SUCCESS;
    }
}
