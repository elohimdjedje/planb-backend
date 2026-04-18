<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class FavoriteControllerTest extends WebTestCase
{
    public function testGetFavoritesWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/api/v1/favorites');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAddFavoriteWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request(
            'POST',
            '/api/v1/favorites/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json']
        );
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRemoveFavoriteWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('DELETE', '/api/v1/favorites/1');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
