<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class MediaController extends AbstractController
{
    #[Route('/uploads/{type}/{filename}', name: 'serve_media', methods: ['GET', 'HEAD'])]
    public function serveMedia(Request $request, string $type, string $filename): Response
    {
        $allowedTypes = ['videos', 'images', 'pdf', 'contracts'];
        if (!in_array($type, $allowedTypes, true)) {
            throw $this->createNotFoundException('Type de fichier non autorisé');
        }

        if (str_contains($filename, '..') || str_contains($filename, '/')) {
            throw $this->createNotFoundException('Chemin invalide');
        }

        $filepath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $type . '/' . $filename;

        if (!file_exists($filepath)) {
            throw $this->createNotFoundException('Fichier non trouvé');
        }

        $mimeTypes = [
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
        ];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentType = $mimeTypes[$ext] ?? null;

        // BinaryFileResponse gère Range / 206 pour le streaming (nécessaire pour iOS / expo-video).
        $response = new BinaryFileResponse($filepath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        if ($contentType) {
            $response->headers->set('Content-Type', $contentType);
        }
        if ($type === 'videos') {
            $response->headers->set('Accept-Ranges', 'bytes');
        }

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Range, Content-Type, Accept, Origin');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    #[Route('/uploads/{type}/{filename}', name: 'serve_media_options', methods: ['OPTIONS'])]
    public function serveMediaOptions(): Response
    {
        $response = new Response();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->setStatusCode(200);

        return $response;
    }
}
