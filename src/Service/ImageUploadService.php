<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ImageUploadService
{
    private ?string $cloudinaryCloudName;
    private ?string $cloudinaryApiKey;
    private ?string $cloudinaryApiSecret;
    private string $uploadDir;
    private bool $cloudinaryEnabled;

    public function __construct(ParameterBagInterface $params)
    {
        // Configuration Cloudinary (optionnel)
        $this->cloudinaryCloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? null;
        $this->cloudinaryApiKey = $_ENV['CLOUDINARY_API_KEY'] ?? null;
        $this->cloudinaryApiSecret = $_ENV['CLOUDINARY_API_SECRET'] ?? null;

        // Vérifier que les clés Cloudinary sont réellement configurées (pas des placeholders)
        $this->cloudinaryEnabled = $this->cloudinaryCloudName 
            && $this->cloudinaryApiKey 
            && $this->cloudinaryApiSecret
            && !str_contains($this->cloudinaryCloudName, 'votre_')
            && !str_contains($this->cloudinaryApiKey, 'votre_')
            && !str_contains($this->cloudinaryApiSecret, 'votre_');

        // Dossier local de fallback
        $this->uploadDir = $params->get('kernel.project_dir') . '/public/uploads/images';
        
        // Créer le dossier si nécessaire
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Upload une image (Cloudinary ou local)
     * 
     * @param UploadedFile $file Fichier uploadé
     * @param string $folder Dossier de destination (ex: 'listings', 'profiles')
     * @return array ['url', 'thumbnail_url', 'key']
     */
    public function uploadImage(UploadedFile $file, string $folder = 'listings'): array
    {
        // Validation du fichier
        $this->validateImage($file);

        // Si Cloudinary est réellement configuré, utiliser Cloudinary
        if ($this->cloudinaryEnabled) {
            return $this->uploadToCloudinary($file, $folder);
        }

        // Sinon, upload local
        return $this->uploadLocal($file, $folder);
    }

    /**
     * Upload un document (image ou PDF) pour les vérifications
     */
    public function uploadDocument(UploadedFile $file, string $folder = 'verification-docs'): array
    {
        $this->validateDocument($file);

        // Les PDF ne passent pas par Cloudinary image upload
        $detectedMime = $file->getMimeType();
        $isImage = str_starts_with($detectedMime, 'image/');

        if ($isImage && $this->cloudinaryEnabled) {
            return $this->uploadToCloudinary($file, $folder);
        }

        return $this->uploadLocalDocument($file, $folder);
    }

    /**
     * Upload vers Cloudinary
     */
    private function uploadToCloudinary(UploadedFile $file, string $folder): array
    {
        $timestamp = time();
        $publicId = $folder . '/' . uniqid() . '_' . $timestamp;

        // Paramètres de l'upload
        $params = [
            'file' => new \CURLFile($file->getPathname()),
            'upload_preset' => 'planb_preset', // À créer dans Cloudinary
            'folder' => $folder,
            'public_id' => $publicId,
            'timestamp' => $timestamp,
            'api_key' => $this->cloudinaryApiKey,
        ];

        // Signature
        $signature = $this->generateCloudinarySignature($params);
        $params['signature'] = $signature;

        // Upload via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudinaryCloudName}/image/upload");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('Erreur upload Cloudinary: ' . $response);
        }

        $result = json_decode($response, true);

        return [
            'url' => $result['secure_url'],
            'thumbnail_url' => $this->getCloudinaryThumbnail($result['public_id']),
            'key' => $result['public_id']
        ];
    }

    /**
     * Upload local (fallback)
     */
    private function uploadLocal(UploadedFile $file, string $folder): array
    {
        $filename = uniqid() . '_' . time() . '.' . $file->guessExtension();
        $subDir = $this->uploadDir . '/' . $folder;

        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        $file->move($subDir, $filename);

        // Créer une miniature
        $thumbnailPath = $this->createThumbnail($subDir . '/' . $filename, 300, 300);

        return [
            'url' => '/uploads/images/' . $folder . '/' . $filename,
            'thumbnail_url' => '/uploads/images/' . $folder . '/thumb_' . $filename,
            'key' => $folder . '/' . $filename
        ];
    }

    /**
     * Upload local pour documents (images + PDF)
     */
    private function uploadLocalDocument(UploadedFile $file, string $folder): array
    {
        $ext = $file->guessExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $ext;
        $subDir = $this->uploadDir . '/' . $folder;

        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        $file->move($subDir, $filename);

        $detectedMime = mime_content_type($subDir . '/' . $filename);
        $thumbnailUrl = null;
        if (str_starts_with($detectedMime, 'image/')) {
            $this->createThumbnail($subDir . '/' . $filename, 300, 300);
            $thumbnailUrl = '/uploads/images/' . $folder . '/thumb_' . $filename;
        }

        return [
            'url' => '/uploads/images/' . $folder . '/' . $filename,
            'thumbnail_url' => $thumbnailUrl,
            'key' => $folder . '/' . $filename
        ];
    }

    /**
     * Valider un document (image ou PDF) pour les vérifications
     */
    private function validateDocument(UploadedFile $file): void
    {
        $maxSize = 10 * 1024 * 1024; // 10 MB
        if ($file->getSize() > $maxSize) {
            throw new \Exception('Fichier trop volumineux (max 10 MB)');
        }

        if ($file->getSize() === 0) {
            throw new \Exception('Le fichier est vide');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        $detectedMime = $file->getMimeType();
        if (!in_array($detectedMime, $allowedMimes)) {
            throw new \Exception('Format non autorisé (JPG, PNG, WebP, PDF uniquement)');
        }

        // Validations supplémentaires pour les images
        if (str_starts_with($detectedMime, 'image/')) {
            $this->validateImageSpecific($file, $detectedMime);
        }

        // Validation signature PDF
        if ($detectedMime === 'application/pdf') {
            $header = file_get_contents($file->getPathname(), false, null, 0, 5);
            if ($header !== '%PDF-') {
                throw new \Exception('Le fichier PDF semble corrompu');
            }
        }
    }

    /**
     * Valider l'image avec vérifications strictes anti-corruption
     */
    private function validateImage(UploadedFile $file): void
    {
        // Taille max: 5 MB
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new \Exception('Image trop volumineuse (max 5 MB)');
        }

        // Vérifier que le fichier n'est pas vide
        if ($file->getSize() === 0) {
            throw new \Exception('Le fichier est vide');
        }

        // Types autorisés
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $detectedMime = $file->getMimeType();
        if (!in_array($detectedMime, $allowedMimes)) {
            throw new \Exception('Format d\'image non autorisé (JPG, PNG, WebP, GIF uniquement)');
        }

        $this->validateImageSpecific($file, $detectedMime);
    }

    /**
     * Validations spécifiques aux images (magic bytes, dimensions, extension)
     */
    private function validateImageSpecific(UploadedFile $file, string $detectedMime): void
    {
        // Vérification anti-corruption des magic bytes
        $this->validateImageSignature($file->getPathname(), $detectedMime);

        // Vérifier l'extension du fichier
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions)) {
            throw new \Exception('Extension de fichier non autorisée');
        }

        // Vérifier que c'est une vraie image
        $imageInfo = @getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new \Exception('Le fichier n\'est pas une image valide ou est corrompu');
        }

        // Vérifier les dimensions minimales et maximales
        $minWidth = 300;
        $minHeight = 300;
        $maxWidth = 10000;
        $maxHeight = 10000;

        if ($imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
            throw new \Exception("Les dimensions minimales sont {$minWidth}x{$minHeight}px");
        }

        if ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight) {
            throw new \Exception("Les dimensions maximales sont {$maxWidth}x{$maxHeight}px");
        }
    }

    /**
     * Valider la signature du fichier image (magic bytes)
     * Détecte les fichiers corruptus ou manipulés
     */
    private function validateImageSignature(string $filePath, string $mimeType): void
    {
        $fileContent = file_get_contents($filePath, false, null, 0, 12);
        if ($fileContent === false) {
            throw new \Exception('Impossible de lire le fichier');
        }

        // Vérifier les signatures de fichier (magic bytes)
        $validSignatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89PNG\r\n\x1a\n"],
            'image/gif' => ["GIF87a", "GIF89a"],
            'image/webp' => ["RIFF", "WEBP"],
        ];

        if (!isset($validSignatures[$mimeType])) {
            throw new \Exception('Type MIME non vérifié');
        }

        $hasValidSignature = false;
        foreach ($validSignatures[$mimeType] as $signature) {
            if (strpos($fileContent, $signature) === 0) {
                $hasValidSignature = true;
                break;
            }
        }

        if (!$hasValidSignature) {
            throw new \Exception('Le fichier semble corrompu ou manipulé (signature invalide)');
        }
    }

    /**
     * Créer une miniature locale
     */
    private function createThumbnail(string $imagePath, int $width, int $height): string
    {
        $info = getimagesize($imagePath);
        $mime = $info['mime'];

        // Créer l'image source
        switch ($mime) {
            case 'image/jpeg':
                $source = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($imagePath);
                break;
            case 'image/webp':
                $source = imagecreatefromwebp($imagePath);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($imagePath);
                break;
            default:
                throw new \Exception('Type d\'image non supporté pour miniature');
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        // Calculer les dimensions
        $ratio = min($width / $srcWidth, $height / $srcHeight);
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);

        // Créer la miniature
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

        // Sauvegarder
        $thumbPath = dirname($imagePath) . '/thumb_' . basename($imagePath);
        imagejpeg($thumb, $thumbPath, 85);

        imagedestroy($source);
        imagedestroy($thumb);

        return $thumbPath;
    }

    /**
     * Générer signature Cloudinary
     */
    private function generateCloudinarySignature(array $params): string
    {
        unset($params['file']);
        unset($params['api_key']);
        ksort($params);

        $stringToSign = '';
        foreach ($params as $key => $value) {
            $stringToSign .= $key . '=' . $value . '&';
        }
        $stringToSign = rtrim($stringToSign, '&');
        $stringToSign .= $this->cloudinaryApiSecret;

        return sha1($stringToSign);
    }

    /**
     * Obtenir URL miniature Cloudinary
     */
    private function getCloudinaryThumbnail(string $publicId): string
    {
        return "https://res.cloudinary.com/{$this->cloudinaryCloudName}/image/upload/w_300,h_300,c_fill/{$publicId}";
    }

    /**
     * Supprimer une image (Cloudinary ou local)
     */
    public function deleteImage(string $key): bool
    {
        if ($this->cloudinaryCloudName && strpos($key, '/') !== false) {
            // Supprimer de Cloudinary
            return $this->deleteFromCloudinary($key);
        }

        // Supprimer en local
        $filePath = $this->uploadDir . '/' . $key;
        if (file_exists($filePath)) {
            unlink($filePath);
            
            // Supprimer aussi la miniature
            $thumbPath = dirname($filePath) . '/thumb_' . basename($filePath);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
            
            return true;
        }

        return false;
    }

    /**
     * Supprimer de Cloudinary
     */
    private function deleteFromCloudinary(string $publicId): bool
    {
        $timestamp = time();
        
        $params = [
            'public_id' => $publicId,
            'timestamp' => $timestamp,
            'api_key' => $this->cloudinaryApiKey,
        ];

        $signature = $this->generateCloudinarySignature($params);
        $params['signature'] = $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudinaryCloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}
