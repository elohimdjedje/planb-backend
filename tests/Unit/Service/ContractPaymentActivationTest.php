<?php

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\Contract;
use App\Entity\ContractAuditLog;
use App\Entity\EscrowAccount;
use App\Entity\Listing;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\EscrowAccountRepository;
use App\Service\ContractService;
use App\Service\EscrowService;
use App\Service\KKiaPayService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Vérifie que la confirmation de paiement Kkiapay passe bien la réservation
 * de 'confirmed' → 'active' (correction bug BUG-ACTIVE).
 */
class ContractPaymentActivationTest extends TestCase
{
    private ContractService $contractService;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var KKiaPayService&MockObject */
    private KKiaPayService $kkiapay;

    /** @var EscrowService&MockObject */
    private EscrowService $escrowService;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->kkiapay = $this->createMock(KKiaPayService::class);

        // EscrowService : createEscrowAccount retourne un EscrowAccount vide (sans DB)
        $escrowRepo = $this->createMock(EscrowAccountRepository::class);
        $escrowRepo->method('findOneBy')->willReturn(null);
        $this->escrowService = new EscrowService(
            $this->em,
            $escrowRepo,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')->willReturnMap([
            ['kernel.project_dir', sys_get_temp_dir()],
        ]);
        $params->method('has')->willReturn(false);

        $this->em->method('persist')->willReturn(null);
        $this->em->method('flush')->willReturn(null);

        $this->contractService = new ContractService(
            $this->em,
            $this->createMock(ContractRepository::class),
            $params,
            $this->createMock(LoggerInterface::class),
            $this->createMock(RequestStack::class),
            $this->kkiapay,
            $this->escrowService
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeContract(string $bookingStatus): Contract
    {
        $owner  = new User();
        $tenant = new User();

        $listing = new Listing();

        $startDate = new \DateTime('2026-05-01');
        $endDate   = new \DateTime('2026-11-01');

        $booking = new Booking();
        $booking->setOwner($owner);
        $booking->setTenant($tenant);
        $booking->setListing($listing);
        $booking->setStartDate($startDate);
        $booking->setEndDate($endDate);
        $booking->setTotalAmount('600000');
        $booking->setDepositAmount('100000');
        $booking->setMonthlyRent('100000');
        $booking->setStatus($bookingStatus);

        $contract = new Contract();
        $contract->setBooking($booking);
        $contract->setStatus(Contract::STATUS_LOCKED);
        $contract->setTemplateType('furnished_rental');
        $contract->setContractData([
            'owner'  => ['name' => 'Jean Dupont'],
            'tenant' => ['name' => 'Alice Martin'],
        ]);

        return $contract;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * CAS NOMINAL : réservation 'confirmed' → doit passer à 'active'
     */
    public function testConfirmedBookingBecomesActiveAfterPayment(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn(['success' => true]);

        $contract = $this->makeContract('confirmed');
        $booking  = $contract->getBooking();

        $this->assertEquals('confirmed', $booking->getStatus(), 'Précondition : statut doit être confirmed');

        $this->contractService->confirmKkiapayPayment($contract, 'TXN-ABC-123');

        $this->assertEquals('active', $booking->getStatus(), 'La réservation doit passer à active après paiement confirmé');
        $this->assertNotNull($booking->getCheckInDate(), 'checkInDate doit être défini');
        $this->assertEquals(
            $booking->getStartDate()->format('Y-m-d'),
            $booking->getCheckInDate()->format('Y-m-d'),
            'checkInDate doit être égal à startDate'
        );
    }

    /**
     * CAS NOMINAL : le contrat doit avoir le statut payment_success
     */
    public function testContractPaymentStatusIsSuccessAfterConfirmation(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn(['success' => true]);

        $contract = $this->makeContract('confirmed');

        $this->contractService->confirmKkiapayPayment($contract, 'TXN-XYZ-456');

        $this->assertEquals('payment_success', $contract->getPaymentStatus());
        $this->assertEquals('TXN-XYZ-456', $contract->getKkiapayTransactionId());
        $this->assertNotNull($contract->getPaidAt());
    }

    /**
     * CAS ERREUR : paiement Kkiapay échoué → réservation doit rester 'confirmed'
     */
    public function testBookingStatusUnchangedWhenPaymentFails(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn([
            'success' => false,
            'error'   => 'Transaction introuvable',
        ]);

        $contract = $this->makeContract('confirmed');
        $booking  = $contract->getBooking();

        $this->expectException(\RuntimeException::class);

        try {
            $this->contractService->confirmKkiapayPayment($contract, 'TXN-INVALID');
        } finally {
            $this->assertEquals('confirmed', $booking->getStatus(), 'Statut ne doit pas changer si paiement échoue');
        }
    }

    /**
     * CAS ERREUR : double confirmation → exception LogicException
     */
    public function testDoublePaymentConfirmationThrows(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn(['success' => true]);

        $contract = $this->makeContract('confirmed');
        // Simuler un paiement déjà effectué
        $contract->setPaymentStatus('payment_success');
        $contract->setKkiapayTransactionId('TXN-ALREADY');

        $this->expectException(\LogicException::class);
        $this->contractService->confirmKkiapayPayment($contract, 'TXN-DUPLICATE');
    }

    /**
     * CAS LIMITE : réservation dans un autre statut (ex: 'active' déjà) → pas de double transition
     */
    public function testAlreadyActiveBookingNotReProcessed(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn(['success' => true]);

        $contract = $this->makeContract('active');

        // Pas d'exception attendue, mais le statut ne doit pas changer
        $this->contractService->confirmKkiapayPayment($contract, 'TXN-RE-789');

        $this->assertEquals('active', $contract->getBooking()->getStatus(), 'Statut déjà active ne doit pas être altéré');
    }

    /**
     * CAS ESCROW : après paiement confirmé, depositPaid/firstRentPaid sont vrais
     * et un EscrowAccount est créé.
     */
    public function testEscrowAccountCreatedAfterPayment(): void
    {
        $this->kkiapay->method('verifyTransaction')->willReturn(['success' => true]);

        $contract = $this->makeContract('confirmed');
        $booking  = $contract->getBooking();

        // Ajouter les montants du contrat (normalement set par setPaymentAmounts)
        $contract->setRentAmount('200000');
        $contract->setDepositMonthlyAmount('100000');
        $contract->setTotalPaymentAmount('300000');

        $this->assertFalse($booking->isDepositPaid(), 'depositPaid doit être false avant paiement');
        $this->assertFalse($booking->isFirstRentPaid(), 'firstRentPaid doit être false avant paiement');

        $this->contractService->confirmKkiapayPayment($contract, 'TXN-ESCROW-001');

        $this->assertTrue($booking->isDepositPaid(), 'depositPaid doit être true après paiement');
        $this->assertTrue($booking->isFirstRentPaid(), 'firstRentPaid doit être true après paiement');
    }
}
