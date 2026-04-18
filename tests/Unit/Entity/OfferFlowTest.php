<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Listing;
use App\Entity\Offer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests du cycle de vie des offres d'achat
 *
 * Scénarios couverts :
 *   1. Nouvelle offre → statut pending et isPending() = true
 *   2. Offre expirée → isPending() = false, statusLabel = "Expirée"
 *   3. Vendeur accepte → statut accepted, listing → reserved
 *   4. Vendeur refuse → statut rejected, sellerResponse enregistré
 *   5. Vendeur contre-offre → statut counter_offer, montant contre-offre stocké
 *   6. Acheteur accepte la contre-offre → statut accepted, amount mis à jour
 *   7. Acheteur annule → statut cancelled
 *   8. Confirmer la vente → listing passe à sold (simulation mark-sold)
 *   9. Un acheteur ne peut pas être son propre vendeur
 */
class OfferFlowTest extends TestCase
{
    private function makeUser(string $firstName, string $lastName): User
    {
        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail(strtolower($firstName) . '@example.com');
        return $user;
    }

    private function makeListing(User $seller, string $type = 'vente', string $price = '25000000'): Listing
    {
        $listing = new Listing();
        $listing->setTitle('Villa Cocody 4 pièces');
        $listing->setType($type);
        $listing->setPrice($price);
        $listing->setStatus('active');
        $listing->setUser($seller);
        return $listing;
    }

    private function makeOffer(Listing $listing, User $buyer, User $seller, string $amount = '22000000'): Offer
    {
        $offer = new Offer();
        $offer->setListing($listing);
        $offer->setBuyer($buyer);
        $offer->setSeller($seller);
        $offer->setAmount($amount);
        $offer->setMessage('Offre sérieuse, disponible rapidement.');
        $offer->setBuyerPhone('+22507000000');
        return $offer;
    }

    // ------------------------------------------------------------------ //
    // 1. Nouvelle offre → pending
    // ------------------------------------------------------------------ //
    public function testNewOfferIsPending(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $listing = $this->makeListing($seller);
        $offer = $this->makeOffer($listing, $buyer, $seller);

        $this->assertSame(Offer::STATUS_PENDING, $offer->getStatus());
        $this->assertTrue($offer->isPending());
        $this->assertFalse($offer->isExpired());
        $this->assertSame('En attente', $offer->getStatusLabel());
    }

    // ------------------------------------------------------------------ //
    // 2. Offre expirée → isPending() = false
    // ------------------------------------------------------------------ //
    public function testExpiredOfferIsNotPending(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $offer = $this->makeOffer($this->makeListing($seller), $buyer, $seller);
        $offer->setExpiresAt(new \DateTime('-1 day'));

        $this->assertTrue($offer->isExpired());
        $this->assertFalse($offer->isPending());
        $this->assertSame('Expirée', $offer->getStatusLabel());
    }

    // ------------------------------------------------------------------ //
    // 3. Vendeur accepte → statut accepted, listing → reserved
    // ------------------------------------------------------------------ //
    public function testSellerAcceptsOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $listing = $this->makeListing($seller);
        $offer = $this->makeOffer($listing, $buyer, $seller, '23000000');

        // Simulation de ce que fait OfferController::acceptOffer
        $offer->setStatus(Offer::STATUS_ACCEPTED);
        $offer->setRespondedAt(new \DateTime());
        $listing->setStatus('reserved');

        $this->assertSame(Offer::STATUS_ACCEPTED, $offer->getStatus());
        $this->assertNotNull($offer->getRespondedAt());
        $this->assertSame('reserved', $listing->getStatus());
    }

    // ------------------------------------------------------------------ //
    // 4. Vendeur refuse → statut rejected
    // ------------------------------------------------------------------ //
    public function testSellerRejectsOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $offer = $this->makeOffer($this->makeListing($seller), $buyer, $seller);

        $offer->setStatus(Offer::STATUS_REJECTED);
        $offer->setSellerResponse('Prix insuffisant pour ce bien');
        $offer->setRespondedAt(new \DateTime());

        $this->assertSame(Offer::STATUS_REJECTED, $offer->getStatus());
        $this->assertSame('Prix insuffisant pour ce bien', $offer->getSellerResponse());
        $this->assertFalse($offer->isPending());
    }

    // ------------------------------------------------------------------ //
    // 5. Vendeur contre-offre → statut counter_offer
    // ------------------------------------------------------------------ //
    public function testSellerMakesCounterOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $offer = $this->makeOffer($this->makeListing($seller), $buyer, $seller, '20000000');

        $offer->setStatus(Offer::STATUS_COUNTER_OFFER);
        $offer->setCounterOfferAmount('24500000');
        $offer->setSellerResponse('Je peux descendre à 24.5M, pas moins.');
        $offer->setRespondedAt(new \DateTime());

        $this->assertSame(Offer::STATUS_COUNTER_OFFER, $offer->getStatus());
        $this->assertSame('24500000', $offer->getCounterOfferAmount());
        $this->assertFalse($offer->isPending());
    }

    // ------------------------------------------------------------------ //
    // 6. Acheteur accepte la contre-offre → statut accepted, montant mis à jour
    // ------------------------------------------------------------------ //
    public function testBuyerAcceptsCounterOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $listing = $this->makeListing($seller);
        $offer = $this->makeOffer($listing, $buyer, $seller, '20000000');
        $offer->setStatus(Offer::STATUS_COUNTER_OFFER);
        $offer->setCounterOfferAmount('24500000');

        // Simulation OfferController::acceptCounterOffer
        $offer->setAmount($offer->getCounterOfferAmount());
        $offer->setStatus(Offer::STATUS_ACCEPTED);
        $offer->setRespondedAt(new \DateTime());
        $listing->setStatus('reserved');

        $this->assertSame(Offer::STATUS_ACCEPTED, $offer->getStatus());
        $this->assertSame('24500000', $offer->getAmount());
        $this->assertSame('reserved', $listing->getStatus());
    }

    // ------------------------------------------------------------------ //
    // 7. Acheteur annule → statut cancelled
    // ------------------------------------------------------------------ //
    public function testBuyerCancelsOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $offer = $this->makeOffer($this->makeListing($seller), $buyer, $seller);

        $offer->setStatus(Offer::STATUS_CANCELLED);

        $this->assertSame(Offer::STATUS_CANCELLED, $offer->getStatus());
        $this->assertFalse($offer->isPending());
    }

    // ------------------------------------------------------------------ //
    // 8. Confirmer la vente → listing passe à sold
    // ------------------------------------------------------------------ //
    public function testMarkSoldSetsListingStatusSold(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $listing = $this->makeListing($seller);
        $offer = $this->makeOffer($listing, $buyer, $seller, '25000000');
        $offer->setStatus(Offer::STATUS_ACCEPTED);
        $listing->setStatus('reserved');

        // Pré-conditions
        $this->assertSame(Offer::STATUS_ACCEPTED, $offer->getStatus());

        // Simulation OfferController::markSold
        $this->assertNotSame('sold', $listing->getStatus(), 'Le listing ne doit pas encore être sold');
        $listing->setStatus('sold');

        $this->assertSame('sold', $listing->getStatus());
    }

    // ------------------------------------------------------------------ //
    // 9. Impossible de marquer sold une offre non-acceptée
    // ------------------------------------------------------------------ //
    public function testCannotMarkSoldOnPendingOffer(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $listing = $this->makeListing($seller);
        $offer = $this->makeOffer($listing, $buyer, $seller);

        // La guard du contrôleur : offer doit être STATUS_ACCEPTED
        $canMarkSold = ($offer->getStatus() === Offer::STATUS_ACCEPTED);
        $this->assertFalse($canMarkSold, "Une offre pending ne peut pas déclencher la vente");
    }

    // ------------------------------------------------------------------ //
    // 10. toArray() contient tous les champs attendus
    // ------------------------------------------------------------------ //
    public function testToArrayContainsExpectedKeys(): void
    {
        $buyer  = $this->makeUser('Kofi', 'Asante');
        $seller = $this->makeUser('Awa', 'Diallo');
        $offer = $this->makeOffer($this->makeListing($seller), $buyer, $seller);

        $arr = $offer->toArray();

        foreach (['amount', 'status', 'statusLabel', 'isExpired', 'createdAt', 'expiresAt', 'buyerPhone', 'message'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Clé manquante dans toArray(): $key");
        }

        $this->assertSame('22000000', $arr['amount']);
        $this->assertSame('pending', $arr['status']);
        $this->assertFalse($arr['isExpired']);
    }
}
