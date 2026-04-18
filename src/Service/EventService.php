<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\TicketPurchase;
use App\Entity\TicketType;

/**
 * Service pour gérer la logique métier des événements
 */
class EventService
{
    /**
     * Calculer les frais de service (1% du montant total)
     * 
     * @param float $amount Montant total
     * @return float Frais de service
     */
    public function calculateServiceFee(float $amount): float
    {
        // 1% de commission validée par le client
        return round($amount * 0.01, 2);
    }

    /**
     * Vérifier la disponibilité des billets avant achat
     * 
     * @param Event $event Événement
     * @param array $tickets Tableau de billets demandés ['ticket_type_id' => quantity]
     * @return bool True si tous les billets sont disponibles
     */
    public function checkTicketAvailability(Event $event, array $tickets): bool
    {
        foreach ($tickets as $ticketTypeId => $quantity) {
            $ticketType = null;
            
            foreach ($event->getTicketTypes() as $type) {
                if ($type->getId() === (int) $ticketTypeId) {
                    $ticketType = $type;
                    break;
                }
            }

            if (!$ticketType) {
                return false;
            }

            if (!$ticketType->isAvailable() || $ticketType->getAvailableQuantity() < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculer le montant total pour un achat de billets
     * 
     * @param TicketType $ticketType Type de billet
     * @param int $quantity Quantité
     * @return array ['subtotal' => float, 'serviceFee' => float, 'total' => float]
     */
    public function calculateTicketTotal(TicketType $ticketType, int $quantity): array
    {
        $subtotal = (float) $ticketType->getPrice() * $quantity;
        $serviceFee = $this->calculateServiceFee($subtotal);
        $total = $subtotal + $serviceFee;

        return [
            'subtotal' => round($subtotal, 2),
            'serviceFee' => $serviceFee,
            'total' => round($total, 2)
        ];
    }

    /**
     * Envoyer la confirmation de billet par email/SMS
     * NOTE: Cette méthode devra être complétée avec votre service d'email/SMS
     * 
     * @param TicketPurchase $purchase Achat de billet
     * @return void
     */
    public function sendTicketConfirmation(TicketPurchase $purchase): void
    {
        // TODO: Implémenter l'envoi d'email avec QR Code
        // Vous pouvez utiliser Symfony Mailer ou un service SMS comme Twilio
        
        $event = $purchase->getEvent();
        $ticketType = $purchase->getTicketType();
        
        // Email content template:
        // - Event name, date, location
        // - Ticket type and quantity
        // - QR Code image
        // - Instructions for entry
        
        // SMS content template:
        // - "Votre billet pour {$event->getTitle()} est confirmé. 
        //    QR Code: {link_to_download}"
    }
}
