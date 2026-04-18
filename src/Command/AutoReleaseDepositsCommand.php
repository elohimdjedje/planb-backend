<?php

namespace App\Command;

use App\Service\SecureDepositService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande Cron — Déblocage automatique des cautions sécurisées.
 *
 * À exécuter toutes les heures (ou plus fréquemment) :
 *   php bin/console app:escrow:auto-release
 *
 * Règles :
 *  1) 72h après fin de location sans signalement → remboursement total
 *  2) 7j après ouverture de litige sans accord → remboursement total
 */
#[AsCommand(
    name: 'app:escrow:auto-release',
    description: 'Déblocage automatique des cautions sécurisées (72h / 7j)',
)]
class AutoReleaseDepositsCommand extends Command
{
    public function __construct(
        private SecureDepositService $depositService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Déblocage automatique des cautions sécurisées');

        // 1. Délai 72h expiré sans litige
        $count72h = $this->depositService->processExpired72h();
        if ($count72h > 0) {
            $io->success("$count72h dépôt(s) remboursé(s) (délai 72h expiré sans signalement)");
        }

        // 2. Litige ouvert > 7 jours sans accord
        $count7j = $this->depositService->processExpired7j();
        if ($count7j > 0) {
            $io->success("$count7j dépôt(s) remboursé(s) (litige > 7 jours sans accord)");
        }

        $total = $count72h + $count7j;

        if ($total === 0) {
            $io->info('Aucun dépôt à débloquer automatiquement.');
        } else {
            $this->logger->info("[Cron] Auto-release terminé : {$total} dépôt(s) traité(s)");
        }

        return Command::SUCCESS;
    }
}
