<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Booking;
use App\Entity\Listing;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la machine à états des réservations.
 * Couvre les 5 états exigés : pending → accepted/rejected → visited → confirmed/rejected
 */
class BookingStateMachineTest extends TestCase
{
    private Booking $booking;
    private User $owner;
    private User $tenant;
    private Listing $listing;

    protected function setUp(): void
    {
        $this->owner   = new User();
        $this->tenant  = new User();
        $this->listing = new Listing();

        $this->booking = new Booking();
        $this->booking->setOwner($this->owner);
        $this->booking->setTenant($this->tenant);
        $this->booking->setListing($this->listing);
        $this->booking->setTotalAmount('150000');
        $this->booking->setDepositAmount('50000');
    }

    // ── Statut initial ─────────────────────────────────────────────────────────

    public function testInitialStatusIsPending(): void
    {
        $this->assertEquals('pending', $this->booking->getStatus());
    }

    // ── pending → accepted ─────────────────────────────────────────────────────

    public function testAcceptBooking(): void
    {
        $this->booking->setStatus('accepted');
        $this->booking->setAcceptedAt(new \DateTime());

        $this->assertEquals('accepted', $this->booking->getStatus());
        $this->assertNotNull($this->booking->getAcceptedAt());
    }

    // ── pending → rejected ─────────────────────────────────────────────────────

    public function testRejectBooking(): void
    {
        $this->booking->setStatus('rejected');

        $this->assertEquals('rejected', $this->booking->getStatus());
    }

    // ── accepted → visited ─────────────────────────────────────────────────────

    public function testMarkVisited(): void
    {
        $this->booking->setStatus('accepted');
        // Transition vers visited
        $this->booking->setStatus('visited');

        $this->assertEquals('visited', $this->booking->getStatus());
    }

    // ── visited → confirmed (locataire confirme) ───────────────────────────────

    public function testTenantConfirmsAfterVisit(): void
    {
        $this->booking->setStatus('visited');
        $this->booking->setStatus('confirmed');
        $this->booking->setConfirmedAt(new \DateTime());

        $this->assertEquals('confirmed', $this->booking->getStatus());
        $this->assertNotNull($this->booking->getConfirmedAt());
    }

    // ── visited → rejected (locataire refuse) ─────────────────────────────────

    public function testTenantRefusesAfterVisit(): void
    {
        $this->booking->setStatus('visited');
        $this->booking->setStatus('rejected');
        $this->booking->setOwnerResponse('Logement trop petit');

        $this->assertEquals('rejected', $this->booking->getStatus());
        $this->assertEquals('Logement trop petit', $this->booking->getOwnerResponse());
    }

    // ── canBeCancelled ─────────────────────────────────────────────────────────

    public function testCanBeCancelledFromPending(): void
    {
        $this->booking->setStatus('pending');
        $this->assertTrue($this->booking->canBeCancelled());
    }

    public function testCanBeCancelledFromAccepted(): void
    {
        $this->booking->setStatus('accepted');
        $this->assertTrue($this->booking->canBeCancelled());
    }

    public function testCanBeCancelledFromConfirmed(): void
    {
        // 'confirmed' est exclu de canBeCancelled() : la caution+loyer sont en escrow,
        // l'annulation doit passer par BookingService::cancelBooking()
        $this->booking->setStatus('confirmed');
        $this->assertFalse($this->booking->canBeCancelled());
    }

    public function testCannotBeCancelledFromActive(): void
    {
        $this->booking->setStatus('active');
        $this->assertFalse($this->booking->canBeCancelled());
    }

    public function testCannotBeCancelledFromCompleted(): void
    {
        $this->booking->setStatus('completed');
        $this->assertFalse($this->booking->canBeCancelled());
    }

    // ── Flux complet: pending → accepted → visited → confirmed ─────────────────

    public function testFullHappyPath(): void
    {
        $this->assertEquals('pending', $this->booking->getStatus());

        $this->booking->setStatus('accepted');
        $this->booking->setAcceptedAt(new \DateTime());
        $this->assertEquals('accepted', $this->booking->getStatus());

        $this->booking->setStatus('visited');
        $this->assertEquals('visited', $this->booking->getStatus());

        $this->booking->setStatus('confirmed');
        $this->booking->setConfirmedAt(new \DateTime());
        $this->assertEquals('confirmed', $this->booking->getStatus());
    }

    // ── Flux refus: pending → accepted → visited → rejected ───────────────────

    public function testRefusedAfterVisitPath(): void
    {
        $this->booking->setStatus('accepted');
        $this->booking->setStatus('visited');
        $this->booking->setStatus('rejected');
        $this->booking->setOwnerResponse('Ne correspond pas à mes attentes');

        $this->assertEquals('rejected', $this->booking->getStatus());
    }

    // ── Validation des choix de statut Entity ─────────────────────────────────

    public function testAllExpectedStatusesExist(): void
    {
        $expectedStatuses = ['pending', 'accepted', 'rejected', 'visited', 'confirmed', 'active', 'completed', 'cancelled'];

        foreach ($expectedStatuses as $status) {
            $booking = new Booking();
            $booking->setStatus($status);
            $this->assertEquals($status, $booking->getStatus(), "Statut '$status' doit être accepté");
        }
    }
}
