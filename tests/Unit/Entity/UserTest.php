<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\Listing;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testUserCreation(): void
    {
        $this->assertInstanceOf(User::class, $this->user);
        $this->assertNull($this->user->getId());
        $this->assertContains('ROLE_USER', $this->user->getRoles());
    }

    public function testSetAndGetEmail(): void
    {
        $email = 'test@example.com';
        $this->user->setEmail($email);
        
        $this->assertEquals($email, $this->user->getEmail());
        $this->assertEquals($email, $this->user->getUserIdentifier());
    }

    public function testSetAndGetNames(): void
    {
        $this->user->setFirstName('John');
        $this->user->setLastName('Doe');
        
        $this->assertEquals('John', $this->user->getFirstName());
        $this->assertEquals('Doe', $this->user->getLastName());
        $this->assertEquals('John Doe', $this->user->getFullName());
    }

    public function testGetInitials(): void
    {
        $this->user->setFirstName('John');
        $this->user->setLastName('Doe');
        
        $this->assertEquals('JD', $this->user->getInitials());
    }

    public function testGetInitialsWithEmptyNames(): void
    {
        $this->assertEquals('', $this->user->getInitials());
    }

    public function testDefaultAccountType(): void
    {
        $this->assertEquals('FREE', $this->user->getAccountType());
    }

    public function testSetAccountType(): void
    {
        $this->user->setAccountType('PRO');
        $this->assertEquals('PRO', $this->user->getAccountType());
    }

    public function testIsProWithFreeAccount(): void
    {
        $this->assertFalse($this->user->isPro());
    }

    public function testIsProWithLifetimePro(): void
    {
        $this->user->setIsLifetimePro(true);
        $this->assertTrue($this->user->isPro());
    }

    public function testIsProWithValidSubscription(): void
    {
        $this->user->setAccountType('PRO');
        $this->user->setSubscriptionExpiresAt(new \DateTime('+1 month'));
        
        $this->assertTrue($this->user->isPro());
    }

    public function testIsProWithExpiredSubscription(): void
    {
        $this->user->setAccountType('PRO');
        $this->user->setSubscriptionExpiresAt(new \DateTime('-1 day'));
        
        $this->assertFalse($this->user->isPro());
    }

    public function testRolesAlwaysContainRoleUser(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $roles = $this->user->getRoles();
        
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testSetAndGetPassword(): void
    {
        $password = 'hashedPassword123';
        $this->user->setPassword($password);
        
        $this->assertEquals($password, $this->user->getPassword());
    }

    public function testSetAndGetPhone(): void
    {
        $phone = '+33612345678';
        $this->user->setPhone($phone);
        
        $this->assertEquals($phone, $this->user->getPhone());
    }

    public function testSetAndGetWhatsappPhone(): void
    {
        $whatsapp = '+33612345678';
        $this->user->setWhatsappPhone($whatsapp);
        
        $this->assertEquals($whatsapp, $this->user->getWhatsappPhone());
    }

    public function testSetAndGetBio(): void
    {
        $bio = 'This is my bio';
        $this->user->setBio($bio);
        
        $this->assertEquals($bio, $this->user->getBio());
    }

    public function testSetAndGetLocation(): void
    {
        $this->user->setCountry('CI');
        $this->user->setCity('Abidjan');
        $this->user->setNationality('Ivoirien');
        
        $this->assertEquals('CI', $this->user->getCountry());
        $this->assertEquals('Abidjan', $this->user->getCity());
        $this->assertEquals('Ivoirien', $this->user->getNationality());
    }

    public function testEmailVerification(): void
    {
        $this->assertFalse($this->user->isEmailVerified());
        
        $this->user->setIsEmailVerified(true);
        $this->assertTrue($this->user->isEmailVerified());
    }

    public function testPhoneVerification(): void
    {
        $this->assertFalse($this->user->isPhoneVerified());
        
        $this->user->setIsPhoneVerified(true);
        $this->assertTrue($this->user->isPhoneVerified());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->user->getCreatedAt());
    }

    public function testUpdatedAtIsSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeInterface::class, $this->user->getUpdatedAt());
    }

    public function testAddAndRemoveListing(): void
    {
        $listing = new Listing();
        
        $this->user->addListing($listing);
        $this->assertCount(1, $this->user->getListings());
        $this->assertSame($this->user, $listing->getUser());
        
        $this->user->removeListing($listing);
        $this->assertCount(0, $this->user->getListings());
    }

    public function testEraseCredentials(): void
    {
        // This method should not throw any exception
        $this->user->eraseCredentials();
        $this->assertTrue(true);
    }

    public function testProfilePicture(): void
    {
        $this->assertNull($this->user->getProfilePicture());
        
        $this->user->setProfilePicture('https://example.com/photo.jpg');
        $this->assertEquals('https://example.com/photo.jpg', $this->user->getProfilePicture());
    }
}
