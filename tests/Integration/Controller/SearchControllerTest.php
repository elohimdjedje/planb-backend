<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SearchControllerTest extends WebTestCase
{
    public function testSearchWithQuery(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => 'appartement'
        ]);
        
        $this->assertResponseIsSuccessful();
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);
    }

    public function testSearchWithEmptyQuery(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => ''
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testSearchWithFilters(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => 'villa',
            'category' => 'immobilier',
            'country' => 'CI',
            'minPrice' => '1000000',
            'maxPrice' => '100000000'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testSearchWithLocation(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'country' => 'CI',
            'city' => 'Abidjan'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testSearchWithPagination(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => 'test',
            'page' => 1,
            'limit' => 20
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testSearchWithSorting(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'q' => 'maison',
            'sort' => 'price',
            'order' => 'asc'
        ]);
        
        $this->assertResponseIsSuccessful();
    }

    public function testSearchByType(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/search', [
            'type' => 'location'
        ]);
        
        $this->assertResponseIsSuccessful();
    }
}
