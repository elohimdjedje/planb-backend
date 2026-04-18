<?php
/**
 * Router pour le serveur PHP built-in
 * Permet de servir correctement les fichiers statiques (images, vidéos, etc.)
 */

// Récupérer l'URI demandée
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Si c'est un fichier qui existe et qui n'est pas un fichier PHP
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    $filePath = __DIR__ . '/public' . $uri;
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    // Liste des extensions de fichiers statiques à servir directement
    $staticExtensions = [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'heic',
        // Vidéos
        'mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'm4v',
        // Audio
        'mp3', 'wav', 'ogg', 'aac', 'm4a',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        // Autres
        'css', 'js', 'json', 'xml', 'txt', 'woff', 'woff2', 'ttf', 'eot'
    ];
    
    // Si c'est un fichier statique, le servir directement
    if (in_array($extension, $staticExtensions)) {
        // Définir le Content-Type approprié
        $mimeTypes = [
            // Images
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'heic' => 'image/heic',
            // Vidéos
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'm4v' => 'video/x-m4v',
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            // Documents
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'txt' => 'text/plain',
            // Web
            'css' => 'text/css',
            'js' => 'application/javascript',
            // Fonts
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        
        // Headers pour le support du streaming vidéo (range requests)
        $fileSize = filesize($filePath);
        $start = 0;
        $end = $fileSize - 1;
        
        // Support des Range requests pour les vidéos (important pour iOS)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }
            
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . ($end - $start + 1));
        } else {
            header('HTTP/1.1 200 OK');
            header('Content-Length: ' . $fileSize);
        }
        
        header('Content-Type: ' . $contentType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000');
        
        // Ouvrir et lire le fichier
        $fp = fopen($filePath, 'rb');
        if ($start > 0) {
            fseek($fp, $start);
        }
        
        $buffer = 8192; // 8KB buffer
        $bytesRemaining = $end - $start + 1;
        
        while (!feof($fp) && $bytesRemaining > 0) {
            $bytesToRead = min($buffer, $bytesRemaining);
            echo fread($fp, $bytesToRead);
            $bytesRemaining -= $bytesToRead;
            flush();
        }
        
        fclose($fp);
        return true;
    }
}

// Sinon, router vers index.php (Symfony)
require __DIR__ . '/public/index.php';
