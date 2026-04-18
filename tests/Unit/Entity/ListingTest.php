<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Listing;
use App\Entity\User;
use App\Entity\Image;
use PHPUnit\Framework\TestCase;

class ListingTest extends TestCase
{
    private Listing $listing;

    protected function setUp(): void
    {
        $this->listing = new Listing();
    }

    public function testListingCreation(): void
    {
        $this->assertInstanceOf(Listing::class, $this->listing);
        $this->assertNull($this->listing->getId());
        $this->assertEquals('draft', $this->listing->getStatus());
        $this->assertEquals(0, $this->listing->getViewsCount());
        $this->assertEquals(0, $this->listing->getContactsCount());
    }

    public function testSetAndGetTitle(): void
    {
        $title = 'Belle villa à vendre';
        $this->listing->setTitle($title);
        
        $this->assertEquals($title, $this->listing->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $description = 'Une magnifique villa avec piscine et jardin';
        $this->listing->setDescription($description);
        
        $this->assertEquals($description, $this->listing->getDescription());
    }

    public function testSetAndGetPrice(): void
    {
        $price = '50000000';
        $this->listing->setPrice($price);
        
        $this->assertEquals($price, $this->listing->getPrice());
    }

    public function testDefaultCurrency(): void
    {
        $this->assertEquals('XOF', $this->listing->getCurrency());
    }

    public function testSetAndGetCurrency(): void
    {
        $this->listing->setCurrency('EUR');
        $this->assertEquals('EUR', $this->listing->getCurrency());
    }

    public function testSetAndGetPriceUnit(): void
    {
        $this->listing->setPriceUnit('la nuit');
        $this->assertEquals('la nuit', $this->listing->getPriceUnit());
    }

    public function testSetAndGetCategory(): void
    {
        $this->listing->setCategory('immobilier');
        $this->assertEquals('immobilier', $this->listing->getCategory());
    }

    public function testSetAndGetSubcategory(): void
    {
        $this->listing->setSubcategory('villa');
        $this->assertEquals('villa', $this->listing->getSubcategory());
    }

    public function testDefaultType(): void
    {
        $this->assertEquals('vente', $this->listing->getType());
    }

    public function testSetAndGetType(): void
    {
        $this->listing->setType('location');
        $this->assertEquals('location', $this->listing->getType());
    }

    public function testSetAndGetLocation(): void
    {
        $this->listing->setCountry('CI');
        $this->listing->setCity('Abidjan');
        $this->listing->setCommune('Cocody');
        $this->listing->setQuartier('Riviera');
        $this->listing->setAddress('Rue des Jardins, Villa 12');
        
        $this->assertEquals('CI', $this->listing->getCountry());
        $this->assertEquals('Abidjan', $this->listing->getCity());
        $this->assertEquals('Cocody', $this->listing->getCommune());
        $this->assertEquals('Riviera', $this->listing->getQuartier());
        $this->assertEquals('Rue des Jardins, Villa 12', $this->listing->getAddress());
    }

    public function testSetAndGetStatus(): void
    {
        $this->listing->setStatus('active');
        $this->assertEquals('active', $this->listing->getStatus());
    }

    public function testSetAndGetSpecifications(): void
    {
        $specs = ['bedrooms' => 3, 'bathrooms' => 2, 'surface' => 150];
        $this->listing->setSpecifications($specs);
        
        $this->assertEquals($specs, $this->listing->getSpecifications());
    }

    public function testIncrementViews(): void
    {
        $this->assertEquals(0, $this->listing->getViewsCount());
        
        $this->listing->incrementViews();
        $this->assertEquals(1, $this->listing->getViewsCount());
        
        $this->listing->incrementViews();
        $this->assertEquals(2, $this->listing->getViewsCount());
    }

    public function testIncrementContacts(): void
    {
        $this->assertEquals(0, $this->listing->getContactsCount());
        
        $this->listing->incrementContacts();
        $this->assertEquals(1, $this->listing->getContactsCount());
    }

    public function testIsFeatured(): void
    {
        $this->assertFalse($this->listing->isFeatured());
        
        $this->listing->setIsFeatured(true);
        $this->assertTrue($this->listing->isFeatured());
    }

    public function testIsExpired(): void
    {
        // Par défaut, expire dans 30 jours
        $this->assertFalse($this->listing->isExpired());
        
        // Définir une date passée
        $this->listing->setExpiresAt(new \DateTime('-1 day'));
        $this->assertTrue($this->listing->isExpired());
    }

    public function testIsActive(): void
    {
        // Draft par défaut, donc pas actif
        $this->assertFalse($this->listing->isActive());
        
        // Activer l'annonce
        $this->listing->setStatus('active');
        $this->assertTrue($this->listing->isActive());
        
        // Expirer l'annonce
        $this->listing->setExpiresAt(new \DateTime('-1 day'));
        $this->assertFalse($this->listing->isActive());
    }

    public function testSetAndGetUser(): void
    {
        $user = new User();
        $this->listing->setUser($user);
        
        $this->assertSame($user, $this->listing->getUser());
    }

    public function testAddAndRemoveImage(): void
    {
        $image = new Image();
        
        $this->listing->addImage($image);
        $this->assertCount(1, $this->listing->getImages());
        $this->assertSame($this->listing, $image->getListing());
        
        $this->listing->removeImage($image);
        $this->assertCount(0, $this->listing->getImages());
    }

    public function testGetMainImage(): void
    {
        $this->assertNull($this->listing->getMainImage());
        
        $image1 = new Image();
        $image2 = new Image();
        
        $this->listing->addImage($image1);
        $this->listing->addImage($image2);
        
        $this->assertSame($image1, $this->listing->getMainImage());
    }

    public function testContactInfo(): void
    {
        $this->listing->setContactPhone('+33612345678');
        $this->listing->setContactWhatsapp('+33612345678');
        $this->listing->setContactEmail('contact@example.com');
        
        $this->assertEquals('+33612345678', $this->listing->getContactPhone());
        $this->assertEquals('+33612345678', $this->listing->getContactWhatsapp());
        $this->assertEquals('contact@example.com', $this->listing->getContactEmail());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->listing->getCreatedAt());
    }

    public function testUpdatedAtIsSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeInterface::class, $this->listing->getUpdatedAt());
    }

    public function testExpiresAtIsSetOnConstruction(): void
    {
        $expiresAt = $this->listing->getExpiresAt();
        $this->assertInstanceOf(\DateTimeInterface::class, $expiresAt);
        
        // Devrait expirer dans environ 30 jours
        $diff = $expiresAt->diff(new \DateTime());
        $this->assertGreaterThanOrEqual(29, $diff->days);
        $this->assertLessThanOrEqual(31, $diff->days);
    }
}
