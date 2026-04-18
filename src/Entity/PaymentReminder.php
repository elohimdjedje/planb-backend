<?php

namespace App\Entity;

use App\Repository\PaymentReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PaymentReminderRepository::class)]
#[ORM\Table(name: 'payment_reminders')]
#[ORM\Index(columns: ['payment_id'], name: 'idx_reminders_payment')]
#[ORM\Index(columns: ['user_id'], name: 'idx_reminders_user')]
#[ORM\Index(columns: ['scheduled_at', 'status'], name: 'idx_reminders_scheduled')]
class PaymentReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookingPayment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BookingPayment $payment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le type de rappel est requis')]
    #[Assert\Choice(
        choices: ['7_days_before', '3_days_before', '1_day_before', 'overdue_1', 'overdue_3', 'overdue_7'],
        message: 'Type de rappel invalide'
    )]
    private ?string $reminderType = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Assert\Choice(
        choices: ['pending', 'sent', 'failed'],
        message: 'Statut invalide'
    )]
    private string $status = 'pending';

    #[ORM\Column(options: ['default' => false])]
    private bool $emailSent = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $smsSent = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $pushSent = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de programmation est requise')]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getReminderType(): ?string
    {
        return $this->reminderType;
    }

    public function setReminderType(string $reminderType): static
    {
        $this->reminderType = $reminderType;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isEmailSent(): bool
    {
        return $this->emailSent;
    }

    public function setEmailSent(bool $emailSent): static
    {
        $this->emailSent = $emailSent;
        return $this;
    }

    public function isSmsSent(): bool
    {
        return $this->smsSent;
    }

    public function setSmsSent(bool $smsSent): static
    {
        $this->smsSent = $smsSent;
        return $this;
    }

    public function isPushSent(): bool
    {
        return $this->pushSent;
    }

    public function setPushSent(bool $pushSent): static
    {
        $this->pushSent = $pushSent;
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeInterface $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): static
    {
        $this->sentAt = $sentAt;
        if ($sentAt) {
            $this->status = 'sent';
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Vérifie si le rappel doit être envoyé maintenant
     */
    public function shouldBeSent(): bool
    {
        return $this->status === 'pending' && 
               $this->scheduledAt !== null && 
               $this->scheduledAt <= new \DateTime();
    }

    /**
     * Marque le rappel comme envoyé
     */
    public function markAsSent(): void
    {
        $this->status = 'sent';
        $this->sentAt = new \DateTime();
    }
}
