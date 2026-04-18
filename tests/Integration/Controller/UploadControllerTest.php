<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class UploadControllerTest extends WebTestCase
{
    public function testUploadWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/api/v1/upload');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteImageWithoutAuth(): void
    {
        $client = static::createClient();
        
        $client->request('DELETE', '/api/v1/upload/test-image.jpg');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
