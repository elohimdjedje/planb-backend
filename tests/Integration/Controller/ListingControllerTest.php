<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ListingControllerTest extends WebTestCase
{
    public function testGetListingsWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings');
        
        // Les annonces publiques sont accessibles sans authentification
        $this->assertResponseIsSuccessful();
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);
    }

    public function testGetListingsWithFilters(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings', [
            'category' => 'immobilier',
            'country' => 'CI',
            'city' => 'Abidjan'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testGetListingsWithPagination(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings', [
            'page' => 1,
            'limit' => 10
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testGetSingleListingNotFound(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings/999999');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateListingWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request(
            'POST',
            '/api/v1/listings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Test Listing',
                'description' => 'This is a test listing description with enough characters',
                'price' => '1000000',
                'category' => 'immobilier',
                'subcategory' => 'appartement',
                'type' => 'vente',
                'country' => 'CI',
                'city' => 'Abidjan'
            ])
        );
        
        // Devrait échouer sans authentification
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpdateListingWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request(
            'PUT',
            '/api/v1/listings/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Updated Title'
            ])
        );
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteListingWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('DELETE', '/api/v1/listings/1');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSearchListings(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => 'villa'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testGetListingsByCategory(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings', [
            'category' => 'immobilier'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testGetListingsByType(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/listings', [
            'type' => 'location'
        ]);
        
        $this->assertResponseIsSuccessful();
    }
}
