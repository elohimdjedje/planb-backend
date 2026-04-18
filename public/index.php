<?php

use App\Kernel;

/*
 * PHP built-in server : si l’URL pointe vers un fichier réel (ex. /uploads/listings/photo.jpg),
 * retourner false pour que le serveur le serve en statique. Sinon PHP peut exécuter le binaire
 * comme script → erreur Symfony Runtime "callable expected, int returned from .jpg".
 *
 * Démarrage : php -S 0.0.0.0:8000 -t public public/index.php
 */
if (php_sapi_name() === 'cli-server') {
    $uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    // Ne pas servir les vidéos en statique : le serveur intégré gère mal Range/206,
    // ce qui casse AVPlayer / expo-video sur iOS. On passe par Symfony (MediaController).
    $isVideoPath = str_starts_with($uri, '/uploads/videos/');
    if ($uri !== '/' && $uri !== '' && !str_contains($uri, '..') && !$isVideoPath) {
        $path = realpath(__DIR__ . $uri);
        $root = realpath(__DIR__);
        if ($path && $root && str_starts_with($path, $root) && is_file($path)) {
            return false;
        }
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
