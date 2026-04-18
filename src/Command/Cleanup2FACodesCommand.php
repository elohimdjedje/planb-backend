<?php

namespace App\Command;

use App\Repository\TwoFactorCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-2fa-codes',
    description: 'Supprime les codes 2FA expirés et les sessions en cache (à exécuter via CRON)'
)]
class Cleanup2FACodesCommand extends Command
{
    public function __construct(
        private TwoFactorCodeRepository $twoFactorCodeRepository,
        private EntityManagerInterface $entityManager,
        private CacheItemPoolInterface $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Nettoyage des codes 2FA expirés');

        // Compter les codes expirés
        $expiredCodes = $this->twoFactorCodeRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        $io->info(sprintf('%d code(s) 2FA expiré(s) trouvé(s).', $expiredCodes));

        if ($dryRun) {
            $io->warning('Mode dry-run : aucune suppression effectuée.');
            return Command::SUCCESS;
        }

        if ($expiredCodes > 0) {
            $deleted = $this->twoFactorCodeRepository->deleteExpired();
            $this->entityManager->flush();
            $io->success(sprintf('%d code(s) 2FA supprimé(s).', $deleted));
        } else {
            $io->success('Aucun code expiré à supprimer.');
        }

        return Command::SUCCESS;
    }
}
