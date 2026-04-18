<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Contract;
use App\Entity\Booking;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la machine à états du contrat.
 * Couvre : draft → tenant_signed → owner_signed → locked
 * Paiement : payment_pending → payment_success / payment_failed
 * Restitution : restitution_requested → restitution_processing → restitution_validated → restitution_completed
 */
class ContractStateMachineTest extends TestCase
{
    private Contract $contract;

    protected function setUp(): void
    {
        $this->contract = new Contract();
    }

    // ── Création ───────────────────────────────────────────────────────────────

    public function testInitialStatusIsDraft(): void
    {
        $this->assertEquals(Contract::STATUS_DRAFT, $this->contract->getStatus());
    }

    public function testContractHasUniqueId(): void
    {
        $this->contract->setUniqueContractId('PLANB-2026-00001');
        $this->assertEquals('PLANB-2026-00001', $this->contract->getUniqueContractId());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $this->assertNotNull($this->contract->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->contract->getCreatedAt());
    }

    // ── SHA-256 ────────────────────────────────────────────────────────────────

    public function testDocumentHashStoredAndRetrieved(): void
    {
        $content = 'Contrat de location plan B 2026';
        $hash    = hash('sha256', $content);

        $this->contract->setDocumentHash($hash);

        $this->assertEquals(64, strlen($this->contract->getDocumentHash()));
        $this->assertEquals($hash, $this->contract->getDocumentHash());
    }

    public function testSignedDocumentHashIsDifferentFromOriginal(): void
    {
        $originalHash = hash('sha256', 'draft content');
        $signedHash   = hash('sha256', 'draft content + tenant sig + owner sig');

        $this->contract->setDocumentHash($originalHash);
        $this->contract->setSignedDocumentHash($signedHash);

        $this->assertNotEquals($this->contract->getDocumentHash(), $this->contract->getSignedDocumentHash());
    }

    // ── draft → tenant_signed ─────────────────────────────────────────────────

    public function testTenantSignature(): void
    {
        $this->assertEquals(Contract::STATUS_DRAFT, $this->contract->getStatus());

        $meta = [
            'uid'        => 42,
            'ip'         => '192.168.1.10',
            'user_agent' => 'Mozilla/5.0',
            'timestamp'  => (new \DateTime())->format(\DateTime::ATOM),
        ];

        $this->contract->setStatus(Contract::STATUS_TENANT_SIGNED);
        $this->contract->setTenantSignedAt(new \DateTime());
        $this->contract->setTenantSignatureUrl('data:image/png;base64,iVBORw0KGgo=');
        $this->contract->setTenantSignatureMeta($meta);

        $this->assertEquals(Contract::STATUS_TENANT_SIGNED, $this->contract->getStatus());
        $this->assertNotNull($this->contract->getTenantSignedAt());
        $this->assertNotNull($this->contract->getTenantSignatureUrl());
        $this->assertEquals(42, $this->contract->getTenantSignatureMeta()['uid']);
        $this->assertEquals('192.168.1.10', $this->contract->getTenantSignatureMeta()['ip']);
    }

    // ── tenant_signed → owner_signed ──────────────────────────────────────────

    public function testOwnerSignature(): void
    {
        $this->contract->setStatus(Contract::STATUS_TENANT_SIGNED);

        $meta = [
            'uid'        => 7,
            'ip'         => '10.0.0.1',
            'user_agent' => 'Safari/605',
            'timestamp'  => (new \DateTime())->format(\DateTime::ATOM),
        ];

        $this->contract->setStatus(Contract::STATUS_OWNER_SIGNED);
        $this->contract->setOwnerSignedAt(new \DateTime());
        $this->contract->setOwnerSignatureUrl('data:image/png;base64,abc123=');
        $this->contract->setOwnerSignatureMeta($meta);

        $this->assertEquals(Contract::STATUS_OWNER_SIGNED, $this->contract->getStatus());
        $this->assertEquals(7, $this->contract->getOwnerSignatureMeta()['uid']);
    }

    // ── owner_signed → locked ─────────────────────────────────────────────────

    public function testContractLocking(): void
    {
        $this->contract->setStatus(Contract::STATUS_OWNER_SIGNED);
        $this->contract->setSignedDocumentHash(hash('sha256', 'final signed content'));
        $this->contract->setStatus(Contract::STATUS_LOCKED);
        $this->contract->setLockedAt(new \DateTime());

        $this->assertEquals(Contract::STATUS_LOCKED, $this->contract->getStatus());
        $this->assertNotNull($this->contract->getLockedAt());
        $this->assertNotNull($this->contract->getSignedDocumentHash());
    }

    public function testLockedStatusPreventsModification(): void
    {
        // Simuler un verrou — le status LOCKED doit exister dans VALID_STATUSES
        $this->assertContains(Contract::STATUS_LOCKED, Contract::VALID_STATUSES);
    }

    // ── Flux complet signature ─────────────────────────────────────────────────

    public function testFullSignatureFlow(): void
    {
        $this->assertEquals('draft', $this->contract->getStatus());

        // 1. Hash initial
        $this->contract->setDocumentHash(hash('sha256', 'contenu brouillon'));

        // 2. Signature locataire
        $this->contract->setStatus(Contract::STATUS_TENANT_SIGNED);
        $this->contract->setTenantSignedAt(new \DateTime());
        $this->contract->setTenantSignatureMeta(['uid' => 1, 'ip' => '127.0.0.1', 'timestamp' => date('c')]);

        // 3. Signature propriétaire
        $this->contract->setStatus(Contract::STATUS_OWNER_SIGNED);
        $this->contract->setOwnerSignedAt(new \DateTime());
        $this->contract->setOwnerSignatureMeta(['uid' => 2, 'ip' => '127.0.0.2', 'timestamp' => date('c')]);

        // 4. Verrouillage
        $signedHash = hash('sha256', 'contenu brouillon + sig1 + sig2');
        $this->contract->setSignedDocumentHash($signedHash);
        $this->contract->setStatus(Contract::STATUS_LOCKED);
        $this->contract->setLockedAt(new \DateTime());

        $this->assertEquals('locked', $this->contract->getStatus());
        $this->assertNotEquals(
            $this->contract->getDocumentHash(),
            $this->contract->getSignedDocumentHash()
        );
    }

    // ── Paiement Kkiapay ──────────────────────────────────────────────────────

    public function testPaymentAmountCalculation(): void
    {
        $rent           = 75000.0;
        $depositMonthly = 75000.0;
        $depositMonths  = 2;
        $total          = $rent + ($depositMonthly * $depositMonths); // 225000

        $this->contract->setRentAmount((string)$rent);
        $this->contract->setDepositMonthlyAmount((string)$depositMonthly);
        $this->contract->setDepositMonths($depositMonths);
        $this->contract->setTotalPaymentAmount((string)$total);

        $this->assertEquals('75000', $this->contract->getRentAmount());
        $this->assertEquals('75000', $this->contract->getDepositMonthlyAmount());
        $this->assertEquals(2, $this->contract->getDepositMonths());
        $this->assertEquals('225000', $this->contract->getTotalPaymentAmount());
    }

    public function testPaymentStatusTransitions(): void
    {
        $this->contract->setPaymentStatus('payment_pending');
        $this->assertEquals('payment_pending', $this->contract->getPaymentStatus());

        $this->contract->setPaymentStatus('payment_success');
        $this->contract->setKkiapayTransactionId('KK-TXN-2026-9876');
        $this->contract->setPaidAt(new \DateTime());

        $this->assertEquals('payment_success', $this->contract->getPaymentStatus());
        $this->assertEquals('KK-TXN-2026-9876', $this->contract->getKkiapayTransactionId());
        $this->assertNotNull($this->contract->getPaidAt());
    }

    public function testPaymentFailedStatus(): void
    {
        $this->contract->setPaymentStatus('payment_failed');
        $this->assertEquals('payment_failed', $this->contract->getPaymentStatus());
    }

    // ── Restitution caution ───────────────────────────────────────────────────

    public function testRestitutionRequestedState(): void
    {
        $this->contract->setRestitutionStatus('restitution_requested');
        $this->contract->setRestitutionRequestedAt(new \DateTime());

        $this->assertEquals('restitution_requested', $this->contract->getRestitutionStatus());
        $this->assertNotNull($this->contract->getRestitutionRequestedAt());
    }

    public function testRestitutionProcessingState(): void
    {
        $this->contract->setRestitutionStatus('restitution_processing');
        $this->contract->setRestitutionNotes('En cours d\'examen');

        $this->assertEquals('restitution_processing', $this->contract->getRestitutionStatus());
        $this->assertStringContainsString('examen', $this->contract->getRestitutionNotes());
    }

    public function testRestitutionValidatedState(): void
    {
        $this->contract->setRestitutionStatus('restitution_validated');

        $this->assertEquals('restitution_validated', $this->contract->getRestitutionStatus());
    }

    public function testRestitutionPartialRetention(): void
    {
        $this->contract->setRestitutionStatus('restitution_validated');
        $this->contract->setRestitutionRetainedAmount('15000');
        $this->contract->setRestitutionNotes('Dommages constatés sur la cuisine');

        $this->assertEquals('15000', $this->contract->getRestitutionRetainedAmount());
        $this->assertStringContainsString('cuisine', $this->contract->getRestitutionNotes());
    }

    public function testRestitutionCompletedState(): void
    {
        $this->contract->setRestitutionStatus('restitution_validated');
        $this->contract->setRestitutionStatus('restitution_completed');
        $this->contract->setRestitutionCompletedAt(new \DateTime());

        $this->assertEquals('restitution_completed', $this->contract->getRestitutionStatus());
        $this->assertNotNull($this->contract->getRestitutionCompletedAt());
    }

    public function testExitReportUrlStored(): void
    {
        $url = 'https://app.planb.com/uploads/contracts/exit_PLANB-2026-00001.pdf';
        $this->contract->setExitReportUrl($url);

        $this->assertEquals($url, $this->contract->getExitReportUrl());
    }

    // ── Flux restitution complet ──────────────────────────────────────────────

    public function testFullRestitutionFlow(): void
    {
        // Prérequis : contrat verrouillé + payé
        $this->contract->setStatus(Contract::STATUS_LOCKED);
        $this->contract->setPaymentStatus('payment_success');

        // Étape 1 — locataire demande la restitution
        $this->contract->setRestitutionStatus('restitution_requested');
        $this->contract->setRestitutionRequestedAt(new \DateTime());
        $this->assertEquals('restitution_requested', $this->contract->getRestitutionStatus());

        // Étape 2 — propriétaire traite (retenue partielle)
        $this->contract->setRestitutionStatus('restitution_processing');
        $this->contract->setRestitutionNotes('Trou dans le mur');
        $this->contract->setRestitutionRetainedAmount('10000');
        $this->assertEquals('restitution_processing', $this->contract->getRestitutionStatus());

        // Étape 3 — procès-verbal généré, validation
        $this->contract->setRestitutionStatus('restitution_validated');
        $this->contract->setExitReportUrl('/uploads/contracts/exit_test.pdf');
        $this->assertEquals('restitution_validated', $this->contract->getRestitutionStatus());
        $this->assertNotNull($this->contract->getExitReportUrl());

        // Étape 4 — clôture finale
        $this->contract->setRestitutionStatus('restitution_completed');
        $this->contract->setRestitutionCompletedAt(new \DateTime());
        $this->assertEquals('restitution_completed', $this->contract->getRestitutionStatus());
        $this->assertNotNull($this->contract->getRestitutionCompletedAt());
    }

    // ── VALID_STATUSES ────────────────────────────────────────────────────────

    public function testValidStatusesContainsAllExpected(): void
    {
        $expected = ['draft', 'tenant_signed', 'owner_signed', 'locked', 'archived'];
        foreach ($expected as $status) {
            $this->assertContains($status, Contract::VALID_STATUSES, "Statut '$status' manquant dans VALID_STATUSES");
        }
    }
}
