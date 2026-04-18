<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\BookingPayment;
use App\Entity\Receipt;
use App\Repository\ReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service de génération de quittances PDF
 */
class ReceiptService
{
    private string $receiptsDir;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReceiptRepository $receiptRepository,
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {
        $this->receiptsDir = $params->get('kernel.project_dir') . '/public/uploads/receipts';
        
        if (!is_dir($this->receiptsDir)) {
            mkdir($this->receiptsDir, 0755, true);
        }
    }

    /**
     * Génère une quittance pour un paiement
     */
    public function generateReceipt(BookingPayment $payment, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): Receipt
    {
        // Vérifier si une quittance existe déjà
        $existing = $this->receiptRepository->findOneBy(['payment' => $payment]);
        if ($existing) {
            return $existing;
        }

        $booking = $payment->getBooking();
        
        // Générer le numéro de quittance
        $receiptNumber = Receipt::generateReceiptNumber($booking->getId(), $payment->getId());

        $receipt = new Receipt();
        $receipt->setPayment($payment);
        $receipt->setBooking($booking);
        $receipt->setReceiptNumber($receiptNumber);
        $receipt->setPeriodStart($periodStart);
        $receipt->setPeriodEnd($periodEnd);
        $receipt->setRentAmount($payment->getType() === 'monthly_rent' ? $payment->getAmount() : $booking->getMonthlyRent());
        $receipt->setChargesAmount($booking->getCharges());
        $receipt->setTotalAmount($receipt->getRentAmount() + $receipt->getChargesAmount());

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        // Générer le PDF
        $pdfUrl = $this->generatePdf($receipt);
        $receipt->setPdfUrl($pdfUrl);

        $this->entityManager->flush();

        $this->logger->info('Quittance générée', [
            'receipt_id' => $receipt->getId(),
            'receipt_number' => $receiptNumber,
            'payment_id' => $payment->getId()
        ]);

        return $receipt;
    }

    /**
     * Génère le PDF de la quittance
     */
    private function generatePdf(Receipt $receipt): string
    {
        // Vérifier si dompdf est disponible
        if (!class_exists(\Dompdf\Dompdf::class)) {
            $this->logger->warning('Dompdf non installé, quittance PDF non générée');
            return '';
        }

        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($this->generateReceiptHtml($receipt));
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $filename = $receipt->getReceiptNumber() . '.pdf';
            $filepath = $this->receiptsDir . '/' . $filename;
            
            file_put_contents($filepath, $dompdf->output());

            $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8000';
            return $baseUrl . '/uploads/receipts/' . $filename;
        } catch (\Exception $e) {
            $this->logger->error('Erreur génération PDF quittance', [
                'receipt_id' => $receipt->getId(),
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Génère le HTML de la quittance
     */
    private function generateReceiptHtml(Receipt $receipt): string
    {
        $booking = $receipt->getBooking();
        $owner = $booking->getOwner();
        $tenant = $booking->getTenant();

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quittance de Loyer - {$receipt->getReceiptNumber()}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; }
        .receipt-number { font-size: 18px; font-weight: bold; color: #333; }
        .info-section { margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; margin: 10px 0; }
        .label { font-weight: bold; }
        .amount-section { background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .total { font-size: 20px; font-weight: bold; color: #e67e22; }
        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>QUITTANCE DE LOYER</h1>
        <div class="receipt-number">N° {$receipt->getReceiptNumber()}</div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="label">Propriétaire:</span>
            <span>{$owner->getFirstName()} {$owner->getLastName()}</span>
        </div>
        <div class="info-row">
            <span class="label">Locataire:</span>
            <span>{$tenant->getFirstName()} {$tenant->getLastName()}</span>
        </div>
        <div class="info-row">
            <span class="label">Adresse du bien:</span>
            <span>{$booking->getListing()->getTitle()}</span>
        </div>
        <div class="info-row">
            <span class="label">Période:</span>
            <span>Du {$receipt->getPeriodStart()->format('d/m/Y')} au {$receipt->getPeriodEnd()->format('d/m/Y')}</span>
        </div>
    </div>

    <div class="amount-section">
        <div class="info-row">
            <span class="label">Loyer:</span>
            <span>{$receipt->getRentAmount()} XOF</span>
        </div>
        <div class="info-row">
            <span class="label">Charges:</span>
            <span>{$receipt->getChargesAmount()} XOF</span>
        </div>
        <div class="info-row total">
            <span>TOTAL:</span>
            <span>{$receipt->getTotalAmount()} XOF</span>
        </div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="label">Date d'émission:</span>
            <span>{$receipt->getIssuedAt()->format('d/m/Y à H:i')}</span>
        </div>
        <div class="info-row">
            <span class="label">Mode de paiement:</span>
            <span>{$receipt->getPayment()->getPaymentMethod()}</span>
        </div>
    </div>

    <div class="footer">
        <p>Cette quittance annule tout reçu et vaut justificatif de paiement.</p>
        <p>Plan B - Plateforme de location immobilière</p>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Génère automatiquement les quittances mensuelles pour une réservation active
     */
    public function generateMonthlyReceipts(Booking $booking): void
    {
        if ($booking->getStatus() !== 'active') {
            return;
        }

        $payments = $this->entityManager->getRepository(BookingPayment::class)
            ->findBy(['booking' => $booking, 'type' => 'monthly_rent', 'status' => 'completed']);

        foreach ($payments as $payment) {
            // Vérifier si quittance existe déjà
            $existing = $this->receiptRepository->findOneBy(['payment' => $payment]);
            if ($existing) {
                continue;
            }

            // Calculer la période (mois du paiement)
            $periodStart = clone $payment->getDueDate();
            $periodStart->modify('first day of this month');
            $periodEnd = clone $periodStart;
            $periodEnd->modify('last day of this month');

            $this->generateReceipt($payment, $periodStart, $periodEnd);
        }
    }
}
