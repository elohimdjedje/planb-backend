<?php

namespace App\Entity;

use App\Repository\ReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReceiptRepository::class)]
#[ORM\Table(name: 'receipts')]
#[ORM\Index(columns: ['payment_id'], name: 'idx_receipts_payment')]
#[ORM\Index(columns: ['booking_id'], name: 'idx_receipts_booking')]
#[ORM\Index(columns: ['receipt_number'], name: 'idx_receipts_number')]
class Receipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookingPayment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BookingPayment $payment = null;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank(message: 'Le numéro de quittance est requis')]
    private ?string $receiptNumber = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de début de période est requise')]
    private ?\DateTimeInterface $periodStart = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de fin de période est requise')]
    #[Assert\Expression(
        'this.getPeriodEnd() > this.getPeriodStart()',
        message: 'La date de fin doit être postérieure à la date de début'
    )]
    private ?\DateTimeInterface $periodEnd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant du loyer est requis')]
    #[Assert\Positive(message: 'Le montant du loyer doit être positif')]
    private ?string $rentAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => 0])]
    private string $chargesAmount = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant total est requis')]
    #[Assert\Positive(message: 'Le montant total doit être positif')]
    private ?string $totalAmount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pdfUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $issuedAt = null;

    public function __construct()
    {
        $this->issuedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): ?BookingPayment
    {
        return $this->payment;
    }

    public function setPayment(?BookingPayment $payment): static
    {
        $this->payment = $payment;
        return $this;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): static
    {
        $this->booking = $booking;
        return $this;
    }

    public function getReceiptNumber(): ?string
    {
        return $this->receiptNumber;
    }

    public function setReceiptNumber(string $receiptNumber): static
    {
        $this->receiptNumber = $receiptNumber;
        return $this;
    }

    public function getPeriodStart(): ?\DateTimeInterface
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeInterface $periodStart): static
    {
        $this->periodStart = $periodStart;
        return $this;
    }

    public function getPeriodEnd(): ?\DateTimeInterface
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeInterface $periodEnd): static
    {
        $this->periodEnd = $periodEnd;
        return $this;
    }

    public function getRentAmount(): ?string
    {
        return $this->rentAmount;
    }

    public function setRentAmount(string $rentAmount): static
    {
        $this->rentAmount = $rentAmount;
        $this->calculateTotalAmount();
        return $this;
    }

    public function getChargesAmount(): string
    {
        return $this->chargesAmount;
    }

    public function setChargesAmount(string $chargesAmount): static
    {
        $this->chargesAmount = $chargesAmount;
        $this->calculateTotalAmount();
        return $this;
    }

    private function calculateTotalAmount(): void
    {
        if ($this->rentAmount) {
            $this->totalAmount = (string)((float)$this->rentAmount + (float)$this->chargesAmount);
        }
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function setPdfUrl(?string $pdfUrl): static
    {
        $this->pdfUrl = $pdfUrl;
        return $this;
    }

    public function getIssuedAt(): ?\DateTimeInterface
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeInterface $issuedAt): static
    {
        $this->issuedAt = $issuedAt;
        return $this;
    }

    /**
     * Génère un numéro de quittance unique
     */
    public static function generateReceiptNumber(int $bookingId, int $paymentId): string
    {
        $date = date('Ymd');
        return sprintf('Q-%s-%d-%d', $date, $bookingId, $paymentId);
    }
}
