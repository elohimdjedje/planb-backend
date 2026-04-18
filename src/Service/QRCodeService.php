<?php

namespace App\Service;

use App\Entity\TicketPurchase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service pour gérer les QR Codes des billets
 */
class QRCodeService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Générer un QR Code unique pour un billet
     * Note: Le QR Code est déjà généré automatiquement dans le constructeur de TicketPurchase
     * Cette méthode permet de le régénérer si nécessaire
     * 
     * @param TicketPurchase $purchase Achat de billet
     * @return string Hash du QR Code
     */
    public function generateQRCode(TicketPurchase $purchase): string
    {
        $data = sprintf(
            '%d-%d-%d-%s',
            $purchase->getEvent()->getId(),
            $purchase->getTicketType()->getId(),
            $purchase->getId(),
            $purchase->getPurchasedAt()->format('YmdHis')
        );

        // Generate secure hash
        $qrCode = hash('sha256', $data . random_bytes(16));
        
        // Ensure uniqueness
        $existing = $this->entityManager->getRepository(TicketPurchase::class)
            ->findOneBy(['qrCode' => $qrCode]);
            
        if ($existing) {
            // Recursive call if collision (très rare)
            return $this->generateQRCode($purchase);
        }

        $purchase->setQrCode($qrCode);
        
        return $qrCode;
    }

    /**
     * Vérifier la validité d'un QR Code
     * 
     * @param string $qrCode Hash du QR Code
     * @return TicketPurchase|null Billet trouvé ou null
     */
    public function verifyQRCode(string $qrCode): ?TicketPurchase
    {
        $purchase = $this->entityManager->getRepository(TicketPurchase::class)
            ->findOneBy(['qrCode' => $qrCode]);

        if (!$purchase) {
            return null;
        }

        // Vérifications supplémentaires
        if (!$purchase->isValid()) {
            return null;
        }

        return $purchase;
    }

    /**
     * Marquer un billet comme utilisé (scan à l'entrée)
     * 
     * @param string $qrCode Hash du QR Code
     * @return array ['success' => bool, 'message' => string, 'purchase' => ?TicketPurchase]
     */
    public function scanTicket(string $qrCode): array
    {
        $purchase = $this->verifyQRCode($qrCode);

        if (!$purchase) {
            return [
                'success' => false,
                'message' => 'QR Code invalide ou billet non trouvé',
                'purchase' => null
            ];
        }

        if ($purchase->getStatus() === 'used') {
            return [
                'success' => false,
                'message' => 'Ce billet a déjà été utilisé le ' . $purchase->getUsedAt()->format('d/m/Y à H:i'),
                'purchase' => $purchase
            ];
        }

        if ($purchase->getStatus() !== 'confirmed') {
            return [
                'success' => false,
                'message' => 'Statut du billet invalide: ' . $purchase->getStatus(),
                'purchase' => $purchase
            ];
        }

        // Marquer comme utilisé
        $purchase->markAsUsed();
        $this->entityManager->flush();

        return [
            'success' => true,
            'message' => 'Billet valide - Accès autorisé',
            'purchase' => $purchase
        ];
    }

    /**
     * Générer l'URL du QR Code pour affichage
     * Utilise une librairie externe comme chillerlan/php-qrcode si installée
     * 
     * @param string $qrCodeHash Hash du QR Code
     * @return string URL ou données du QR Code
     */
    public function getQRCodeImageData(string $qrCodeHash): string
    {
        // TODO: Intégrer une librairie QR Code PHP
        // Exemple avec chillerlan/php-qrcode:
        // $qrcode = new QRCode();
        // return $qrcode->render($qrCodeHash);
        
        // Pour l'instant, retourner le hash qui sera utilisé côté frontend
        return $qrCodeHash;
    }
}
