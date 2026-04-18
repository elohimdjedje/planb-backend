<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`users`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'L\'email est requis')]
    #[Assert\Email(message: 'L\'email {{ value }} n\'est pas valide')]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex(pattern: '/^[\+]?[\d\s\-\(\)]{6,30}$/', message: 'Numéro de téléphone invalide')]
    private ?string $phone = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex(pattern: '/^[\+]?[\d\s\-\(\)]{6,30}$/', message: 'Numéro WhatsApp invalide')]
    private ?string $whatsappPhone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'La bio ne doit pas dépasser {{ limit }} caractères')]
    private ?string $bio = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est requis')]
    #[Assert\Length(max: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est requis')]
    #[Assert\Length(max: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['FREE', 'PRO'], message: 'Type de compte invalide')]
    private string $accountType = 'FREE';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $country = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $nationality = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profilePicture = null;

    #[ORM\Column]
    private bool $isEmailVerified = false;

    #[ORM\Column]
    private bool $isPhoneVerified = false;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $verificationBadges = [];

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $verificationCategory = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $verificationStatus = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $subscriptionExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $subscriptionStartDate = null;

    #[ORM\Column]
    private bool $isLifetimePro = false;

    // Modération
    #[ORM\Column]
    private bool $isBanned = false;

    #[ORM\Column]
    private bool $isSuspended = false;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $warningsCount = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $bannedUntil = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $suspendedUntil = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Listing>
     */
    #[ORM\OneToMany(targetEntity: Listing::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $listings;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $payments;

    #[ORM\OneToOne(targetEntity: Subscription::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Subscription $subscription = null;

    public function __construct()
    {
        $this->listings = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->roles = ['ROLE_USER'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    /**
     * Obtenir les initiales du nom complet (pour avatar)
     */
    public function getInitials(): string
    {
        $firstInitial = $this->firstName ? strtoupper(substr($this->firstName, 0, 1)) : '';
        $lastInitial = $this->lastName ? strtoupper(substr($this->lastName, 0, 1)) : '';
        return $firstInitial . $lastInitial;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): static
    {
        $this->accountType = $accountType;
        return $this;
    }

    public function isPro(): bool
    {
        // Si PRO à vie, toujours PRO
        if ($this->isLifetimePro) {
            return true;
        }

        // Sinon, vérifier la date d'expiration
        return $this->accountType === 'PRO' && 
               $this->subscriptionExpiresAt !== null && 
               $this->subscriptionExpiresAt > new \DateTime();
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): static
    {
        $this->nationality = $nationality;
        return $this;
    }

    public function getProfilePicture(): ?string
    {
        return $this->profilePicture;
    }

    public function setProfilePicture(?string $profilePicture): static
    {
        $this->profilePicture = $profilePicture;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->isEmailVerified;
    }

    public function setIsEmailVerified(bool $isEmailVerified): static
    {
        $this->isEmailVerified = $isEmailVerified;
        return $this;
    }

    public function isPhoneVerified(): bool
    {
        return $this->isPhoneVerified;
    }

    public function setIsPhoneVerified(bool $isPhoneVerified): static
    {
        $this->isPhoneVerified = $isPhoneVerified;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getSubscriptionExpiresAt(): ?\DateTimeInterface
    {
        return $this->subscriptionExpiresAt;
    }

    public function setSubscriptionExpiresAt(?\DateTimeInterface $subscriptionExpiresAt): static
    {
        $this->subscriptionExpiresAt = $subscriptionExpiresAt;
        return $this;
    }

    public function getSubscriptionStartDate(): ?\DateTimeInterface
    {
        return $this->subscriptionStartDate;
    }

    public function setSubscriptionStartDate(?\DateTimeInterface $subscriptionStartDate): static
    {
        $this->subscriptionStartDate = $subscriptionStartDate;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Listing>
     */
    public function getListings(): Collection
    {
        return $this->listings;
    }

    public function addListing(Listing $listing): static
    {
        if (!$this->listings->contains($listing)) {
            $this->listings->add($listing);
            $listing->setUser($this);
        }

        return $this;
    }

    public function removeListing(Listing $listing): static
    {
        if ($this->listings->removeElement($listing)) {
            if ($listing->getUser() === $this) {
                $listing->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setUser($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getUser() === $this) {
                $payment->setUser(null);
            }
        }

        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): static
    {
        // unset the owning side of the relation if necessary
        if ($subscription === null && $this->subscription !== null) {
            $this->subscription->setUser(null);
        }

        // set the owning side of the relation if necessary
        if ($subscription !== null && $subscription->getUser() !== $this) {
            $subscription->setUser($this);
        }

        $this->subscription = $subscription;

        return $this;
    }

    public function isLifetimePro(): bool
    {
        return $this->isLifetimePro;
    }

    public function setIsLifetimePro(bool $isLifetimePro): static
    {
        $this->isLifetimePro = $isLifetimePro;
        return $this;
    }

    // Modération
    public function isIsBanned(): bool
    {
        return $this->isBanned;
    }

    public function setIsBanned(bool $isBanned): static
    {
        $this->isBanned = $isBanned;
        return $this;
    }

    public function isIsSuspended(): bool
    {
        return $this->isSuspended;
    }

    public function setIsSuspended(bool $isSuspended): static
    {
        $this->isSuspended = $isSuspended;
        return $this;
    }

    public function getWarningsCount(): ?int
    {
        return $this->warningsCount;
    }

    public function setWarningsCount(?int $warningsCount): static
    {
        $this->warningsCount = $warningsCount;
        return $this;
    }

    public function getBannedUntil(): ?\DateTimeInterface
    {
        return $this->bannedUntil;
    }

    public function setBannedUntil(?\DateTimeInterface $bannedUntil): static
    {
        $this->bannedUntil = $bannedUntil;
        return $this;
    }

    public function getSuspendedUntil(): ?\DateTimeInterface
    {
        return $this->suspendedUntil;
    }

    public function setSuspendedUntil(?\DateTimeInterface $suspendedUntil): static
    {
        $this->suspendedUntil = $suspendedUntil;
        return $this;
    }

    public function getWhatsappPhone(): ?string
    {
        return $this->whatsappPhone;
    }

    public function setWhatsappPhone(?string $whatsappPhone): static
    {
        $this->whatsappPhone = $whatsappPhone;
        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;
        return $this;
    }

    // ========== VERIFICATION BADGES ==========

    public function getVerificationBadges(): ?array
    {
        return $this->verificationBadges;
    }

    public function setVerificationBadges(?array $verificationBadges): static
    {
        $this->verificationBadges = $verificationBadges;
        return $this;
    }

    public function addVerificationBadge(string $badge): static
    {
        if (!in_array($badge, $this->verificationBadges ?? [])) {
            $this->verificationBadges[] = $badge;
        }
        return $this;
    }

    public function removeVerificationBadge(string $badge): static
    {
        $this->verificationBadges = array_values(array_filter(
            $this->verificationBadges ?? [],
            fn($b) => $b !== $badge
        ));
        return $this;
    }

    public function hasBadge(string $badge): bool
    {
        return in_array($badge, $this->verificationBadges ?? []);
    }

    public function getVerificationCategory(): ?string
    {
        return $this->verificationCategory;
    }

    public function setVerificationCategory(?string $verificationCategory): static
    {
        $this->verificationCategory = $verificationCategory;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getVerificationStatus(): ?string
    {
        return $this->verificationStatus;
    }

    public function setVerificationStatus(?string $verificationStatus): static
    {
        $this->verificationStatus = $verificationStatus;
        return $this;
    }

    public function isIdentityVerified(): bool
    {
        return $this->isVerified || !empty($this->verificationBadges);
    }

    public function canPublish(): bool
    {
        return $this->isVerified || !empty($this->verificationBadges);
    }

}
