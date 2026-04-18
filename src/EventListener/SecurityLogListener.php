<?php

namespace App\EventListener;

use App\Entity\SecurityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Écouteur d'événements de sécurité
 * Enregistre les événements d'authentification importants
 */
class SecurityLogListener implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InteractiveLoginEvent::class => 'onInteractiveLogin',
            LogoutEvent::class => 'onLogout',
        ];
    }

    /**
     * Enregistre une connexion réussie
     */
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        
        $log = new SecurityLog();
        $log->setUser($user);
        $log->setAction('LOGIN_SUCCESS');
        $log->setIpAddress($this->getClientIp($request));
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setContext([
            'timestamp' => (new \DateTime())->format('c'),
            'path' => $request->getPathInfo(),
        ]);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * Enregistre une déconnexion
     */
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        
        $log = new SecurityLog();
        $log->setUser($user);
        $log->setAction('LOGOUT');
        $log->setIpAddress($this->getClientIp($request));
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setContext([
            'timestamp' => (new \DateTime())->format('c'),
            'reason' => $event->getResponse() ? 'voluntary' : 'session_expired',
        ]);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * Récupère l'adresse IP du client
     */
    private function getClientIp(Request $request): string
    {
        // Vérifier les en-têtes de proxy
        if ($request->headers->has('X-Forwarded-For')) {
            $ips = explode(',', $request->headers->get('X-Forwarded-For'));
            return trim($ips[0]);
        }

        if ($request->headers->has('X-Real-IP')) {
            return $request->headers->get('X-Real-IP');
        }

        return $request->getClientIp();
    }
}
