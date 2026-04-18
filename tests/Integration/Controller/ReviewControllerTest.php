<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ReviewControllerTest extends WebTestCase
{
    public function testGetUserReviews(): void
    {
        $client = static::createClient();
        
        // Les avis sont publics
        $client->request('GET', '/api/v1/reviews/seller/1');
        
        // Peut être 200 ou 404 selon si l'utilisateur existe
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [Response::HTTP_OK, Response::HTTP_NOT_FOUND]
        );
    }

    public function testCreateReviewWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request(
            'POST',
            '/api/v1/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'userId' => 1,
                'rating' => 5,
                'comment' => 'Excellent vendeur, très professionnel'
            ])
        );
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpdateReviewWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request(
            'PUT',
            '/api/v1/reviews/1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'rating' => 4,
                'comment' => 'Updated review'
            ])
        );

        // PUT sur les avis n'est pas implémenté ; seul DELETE existe → 405 Method Not Allowed
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testDeleteReviewWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('DELETE', '/api/v1/reviews/1');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
