<?php

namespace App\Controller\Api;

use App\Entity\Listing;
use App\Repository\ListingRepository;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/api/v1/listings')]
class VirtualTourController extends AbstractController
{
    public function __construct(
        private ListingRepository $listingRepository,
        private EntityManagerInterface $entityManager,
        private ImageUploadService $imageUploadService,
        private LoggerInterface $logger
    ) {}

    /**
     * Upload une vidéo de présentation pour une annonce
     * 
     * POST /api/v1/listings/{id}/virtual-tour
     */
    #[Route('/{id}/virtual-tour', name: 'app_listing_virtual_tour_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadVideo(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            $listing = $this->listingRepository->find($id);

            if (!$listing) {
                return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
            }

            if ($listing->getUser()->getId() !== $user->getId()) {
                return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
            }

            if ($user->getAccountType() !== 'PRO' && !$user->isLifetimePro()) {
                return $this->json([
                    'error' => 'La vidéo de présentation est disponible uniquement pour les comptes PRO',
                    'upgrade_required' => true
                ], Response::HTTP_FORBIDDEN);
            }

            $file = $request->files->get('video') ?? $request->files->get('virtual_tour');
            
            if (!$file) {
                return $this->json(['error' => 'Fichier vidéo manquant'], Response::HTTP_BAD_REQUEST);
            }

            // IMPORTANT : Seul MP4 est supporté pour la compatibilité mobile (Android/iOS)
            $allowedTypes = ['video/mp4'];
            $fileMimeType = $file->getMimeType();
            
            if (!in_array($fileMimeType, $allowedTypes)) {
                return $this->json([
                    'error' => 'Format vidéo non supporté. Convertissez votre vidéo en MP4 H.264 (codec vidéo: H.264, codec audio: AAC)',
                    'current_format' => $fileMimeType,
                    'supported_formats' => $allowedTypes,
                    'hint' => 'Utilisez eines avec FFmpeg: ffmpeg -i input.mov -c:v libx264 -c:a aac output.mp4'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Max 500 MB
            if ($file->getSize() > 500 * 1024 * 1024) {
                return $this->json([
                    'error' => 'Fichier trop volumineux. Maximum 500 MB'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Capturer les infos AVANT move() car le fichier temp est supprimé après
            $fileSize = $file->getSize();
            $fileMime = $file->getMimeType();
            $fileOriginalName = $file->getClientOriginalName();

            $uploadResult = $this->storeVideoFile($file);

            $listing->setVirtualTourType('video');
            $listing->setVirtualTourUrl($uploadResult['url']);
            $listing->setVirtualTourThumbnail($uploadResult['thumbnail_url'] ?? null);
            $listing->setVirtualTourData([
                'uploaded_at' => (new \DateTime())->format('c'),
                'file_size' => $fileSize,
                'mime_type' => $fileMime,
                'original_name' => $fileOriginalName,
            ]);

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Vidéo ajoutée avec succès',
                'data' => [
                    'type' => 'video',
                    'url' => $listing->getVirtualTourUrl(),
                    'thumbnail' => $listing->getVirtualTourThumbnail()
                ]
            ], Response::HTTP_CREATED);

        } catch (\Throwable $e) {
            $this->logger->error('Erreur upload vidéo', [
                'listing_id' => $id,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->json([
                'error' => 'Erreur lors de l\'upload de la vidéo',
                'detail' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Récupérer la vidéo d'une annonce
     * 
     * GET /api/v1/listings/{id}/virtual-tour
     */
    #[Route('/{id}/virtual-tour', name: 'app_listing_virtual_tour_get', methods: ['GET'])]
    public function getVideo(int $id): JsonResponse
    {
        $listing = $this->listingRepository->find($id);

        if (!$listing || !$listing->hasVirtualTour()) {
            return $this->json(['error' => 'Vidéo non disponible'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'type' => $listing->getVirtualTourType(),
            'url' => $listing->getVirtualTourUrl(),
            'thumbnail' => $listing->getVirtualTourThumbnail(),
            'data' => $listing->getVirtualTourData()
        ]);
    }

    /**
     * Supprimer la vidéo
     * 
     * DELETE /api/v1/listings/{id}/virtual-tour
     */
    #[Route('/{id}/virtual-tour', name: 'app_listing_virtual_tour_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteVideo(int $id): JsonResponse
    {
        $user = $this->getUser();
        $listing = $this->listingRepository->find($id);

        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($listing->getVirtualTourUrl()) {
            try {
                $this->imageUploadService->deleteImage($listing->getVirtualTourUrl());
            } catch (\Exception $e) {
                $this->logger->warning('Erreur suppression fichier vidéo', [
                    'listing_id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $listing->setVirtualTourType(null);
        $listing->setVirtualTourUrl(null);
        $listing->setVirtualTourThumbnail(null);
        $listing->setVirtualTourData(null);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Vidéo supprimée'
        ]);
    }

    /**
     * Upload le fichier vidéo en local (ou Cloudinary si configuré)
     */
    private function storeVideoFile($file): array
    {
        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/videos';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $ext = $file->guessExtension() ?: 'mp4';
        $filename = uniqid('vid_', true) . '.' . $ext;
        $file->move($uploadsDir, $filename);

        return [
            'url' => '/uploads/videos/' . $filename,
            'thumbnail_url' => null,
        ];
    }
}