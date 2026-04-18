<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class UploadController extends AbstractController
{
    /**
     * Upload d'images (temporaire - stockage local)
     * TODO: Intégrer Cloudinary ou AWS S3 en production
     */
    #[Route('/upload', name: 'upload_images', methods: ['POST'])]
    public function uploadImages(Request $request): JsonResponse
    {
        // ✅ Vérifier que l'utilisateur est authentifié
        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'error' => 'Non authentifié',
                'message' => 'Vous devez être connecté pour uploader des images'
            ], Response::HTTP_UNAUTHORIZED);
        }
        
        try {
            // Fichiers reçus (multipart) — mobile envoie souvent le champ « image » unique
            $filesToProcess = [];

            if ($request->files->has('images')) {
                $imagesBag = $request->files->get('images');
                if (is_array($imagesBag)) {
                    foreach ($imagesBag as $f) {
                        if ($f instanceof UploadedFile) {
                            $filesToProcess[] = $f;
                        }
                    }
                }
            }

            foreach (['image', 'file', 'photo'] as $field) {
                if ($request->files->has($field)) {
                    $f = $request->files->get($field);
                    if ($f instanceof UploadedFile) {
                        $filesToProcess[] = $f;
                    }
                }
            }

            if ($filesToProcess === []) {
                foreach ($request->files->all() as $f) {
                    if ($f instanceof UploadedFile) {
                        $filesToProcess[] = $f;
                    } elseif (is_array($f)) {
                        foreach ($f as $inner) {
                            if ($inner instanceof UploadedFile) {
                                $filesToProcess[] = $inner;
                            }
                        }
                    }
                }
            }

            if ($filesToProcess === []) {
                return $this->json([
                    'error' => 'Aucun fichier uploadé',
                ], Response::HTTP_BAD_REQUEST);
            }

            $uploadedUrls = [];
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/listings';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            foreach ($filesToProcess as $idx => $file) {
                if (!$file instanceof UploadedFile) {
                    error_log("Upload: Fichier invalide à l'index $idx");
                    continue;
                }

                // Formats courants + iOS (HEIC) ; parfois image/jpeg mal détectée → octet-stream
                $allowedMimes = [
                    'image/jpeg',
                    'image/jpg',
                    'image/png',
                    'image/webp',
                    'image/gif',
                    'image/svg+xml',
                    'image/bmp',
                    'image/x-icon',
                    'image/heic',
                    'image/heif',
                    'image/heic-sequence',
                ];

                $mimeType = $file->getMimeType();
                $clientMime = $file->getClientMimeType();
                $ext = strtolower($file->guessExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                $imageExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic', 'heif'], true);

                $mimeOk = in_array($mimeType, $allowedMimes, true)
                    || in_array($clientMime, $allowedMimes, true);
                $octetOk = ($mimeType === 'application/octet-stream' || $clientMime === 'application/octet-stream') && $imageExt;

                if (!$mimeOk && !$octetOk) {
                    error_log("Upload: Type MIME non autorisé: client=$clientMime detected=$mimeType ext=$ext");
                    continue;
                }

                // Valider la taille (max 10MB au lieu de 5MB)
                if ($file->getSize() > 10 * 1024 * 1024) {
                    error_log("Upload: Fichier trop grand: " . $file->getSize() . " bytes");
                    continue;
                }

                // Générer un nom unique
                $fileName = uniqid() . '_' . time() . '.' . $file->guessExtension();
                
                // Déplacer le fichier
                $file->move($uploadDir, $fileName);
                
                // Ajouter l'URL publique
                $uploadedUrls[] = '/uploads/listings/' . $fileName;
                error_log("Upload: Image uploadée avec succès: $fileName");
            }

            if (empty($uploadedUrls)) {
                return $this->json([
                    'error' => 'Aucune image valide uploadée'
                ], Response::HTTP_BAD_REQUEST);
            }

            return $this->json([
                'success' => true,
                'urls' => $uploadedUrls,
                'images' => $uploadedUrls, // Compatibilité
                'count' => count($uploadedUrls)
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de l\'upload',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload d'une video (compatibilite clients mobiles legacy)
     */
    #[Route('/upload/video', name: 'upload_video_legacy', methods: ['POST'])]
    public function uploadVideo(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'error' => 'Non authentifie',
                'message' => 'Vous devez etre connecte pour uploader une video'
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $file = $request->files->get('video')
                ?? $request->files->get('virtual_tour')
                ?? $request->files->get('file');

            if (!$file) {
                return $this->json([
                    'error' => 'Aucun fichier video uploade'
                ], Response::HTTP_BAD_REQUEST);
            }

            $allowedMimes = [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-matroska',
                'video/webm',
            ];

            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, $allowedMimes, true)) {
                return $this->json([
                    'error' => 'Format non supporte. Utilisez MP4, MOV, AVI, MKV ou WebM'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Limite 500 Mo
            if ($file->getSize() > 500 * 1024 * 1024) {
                return $this->json([
                    'error' => 'Fichier trop volumineux. Maximum 500 MB'
                ], Response::HTTP_BAD_REQUEST);
            }

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/videos';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = $file->guessExtension() ?: 'mp4';
            $fileName = uniqid('vid_', true) . '.' . $ext;
            $file->move($uploadDir, $fileName);
            $url = '/uploads/videos/' . $fileName;

            return $this->json([
                'success' => true,
                'url' => $url,
                'video' => $url,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de l\'upload video',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Supprimer une image
     */
    #[Route('/upload/{filename}', name: 'delete_image', methods: ['DELETE'])]
    public function deleteImage(string $filename): JsonResponse
    {
        // ✅ Vérifier que l'utilisateur est authentifié
        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'error' => 'Non authentifié',
                'message' => 'Vous devez être connecté pour supprimer des images'
            ], Response::HTTP_UNAUTHORIZED);
        }
        
        try {
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/listings';

            // ✅ SECURITY: Sanitize filename to prevent path traversal attacks
            $safeFilename = basename($filename);
            if ($safeFilename !== $filename || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
                return $this->json([
                    'error' => 'Nom de fichier invalide'
                ], Response::HTTP_BAD_REQUEST);
            }

            $filePath = $uploadDir . '/' . $safeFilename;

            // ✅ SECURITY: Verify resolved path is within upload directory
            $realPath = realpath($filePath);
            $realUploadDir = realpath($uploadDir);
            if ($realPath === false || $realUploadDir === false || !str_starts_with($realPath, $realUploadDir)) {
                return $this->json([
                    'error' => 'Image non trouvée'
                ], Response::HTTP_NOT_FOUND);
            }

            if (file_exists($realPath)) {
                unlink($realPath);
                return $this->json([
                    'success' => true,
                    'message' => 'Image supprimée'
                ]);
            }

            return $this->json([
                'error' => 'Image non trouvée'
            ], Response::HTTP_NOT_FOUND);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la suppression',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
