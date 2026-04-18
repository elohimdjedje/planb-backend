<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TwoFactorCodeRepository;
use App\Service\SecurityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/2fa')]
class TwoFactorAuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TwoFactorCodeRepository $twoFactorCodeRepository,
        private JWTTokenManagerInterface $jwtManager,
        private CacheItemPoolInterface $cache,
        private AuthController $authController,
        private SecurityLogger $securityLogger
    ) {
    }

    #[Route('/verify', name: '2fa_verify', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function verify(Request $request, RateLimiterFactory $twoFactorVerifyLimiter): JsonResponse
    {
        // Rate limiter par IP
        $limiter = $twoFactorVerifyLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json([
                'error' => 'Trop de tentatives de vérification. Réessayez dans 15 minutes.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;
        $twoFactorToken = $data['2fa_token'] ?? null;

        if (!$code || !$twoFactorToken) {
            return $this->json([
                'error' => 'Code et token 2FA requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Valider format du code (6 chiffres uniquement)
        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->json([
                'error' => 'Code invalide. Le code doit être composé de 6 chiffres.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Valider le token 2FA signé et récupérer les données
        $tokenData = $this->authController->validate2FAToken($twoFactorToken);
        if (!$tokenData) {
            return $this->json([
                'error' => 'Session 2FA expirée ou invalide. Veuillez vous reconnecter.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $userId = $tokenData['user_id'];
        $originalIp = $tokenData['ip'];

        // Vérification de l'IP (anti-vol de session 2FA)
        if ($request->getClientIp() !== $originalIp) {
            $this->authController->invalidate2FAToken($twoFactorToken);
            $this->securityLogger->logSuspiciousActivity(
                'IP mismatch on 2FA verify',
                $request,
                ['original_ip' => $originalIp, 'current_ip' => $request->getClientIp()]
            );
            return $this->json([
                'error' => 'Session 2FA invalide. Veuillez vous reconnecter.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Récupérer l'utilisateur
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->authController->invalidate2FAToken($twoFactorToken);
            return $this->json([
                'error' => 'Utilisateur introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        // Récupérer le dernier code 2FA valide
        $twoFactorCode = $this->twoFactorCodeRepository->findLatestValidForUser($user);

        if (!$twoFactorCode) {
            $this->authController->invalidate2FAToken($twoFactorToken);
            return $this->json([
                'error' => 'Code expiré ou introuvable. Veuillez vous reconnecter.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier l'expiration
        if ($twoFactorCode->isExpired()) {
            $this->twoFactorCodeRepository->deleteAllForUser($user);
            $this->authController->invalidate2FAToken($twoFactorToken);
            return $this->json([
                'error' => 'Code expiré. Veuillez vous reconnecter.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier le nombre de tentatives
        if ($twoFactorCode->hasExceededMaxAttempts()) {
            $this->twoFactorCodeRepository->deleteAllForUser($user);
            $this->authController->invalidate2FAToken($twoFactorToken);
            return $this->json([
                'error' => 'Nombre maximum de tentatives atteint. Veuillez vous reconnecter.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Vérifier le code avec password_verify (timing-safe)
        if (!password_verify($code, $twoFactorCode->getCodeHash())) {
            $twoFactorCode->incrementAttempts();
            $this->entityManager->flush();

            $remaining = 5 - $twoFactorCode->getAttempts();

            // Si plus de tentatives, invalider tout
            if ($remaining <= 0) {
                $this->twoFactorCodeRepository->deleteAllForUser($user);
                $this->authController->invalidate2FAToken($twoFactorToken);
            }

            return $this->json([
                'error' => 'Code incorrect.',
                'attempts_remaining' => max(0, $remaining),
            ], Response::HTTP_UNAUTHORIZED);
        }

        // ─── Code valide : émettre le JWT ───

        // Nettoyage complet
        $this->twoFactorCodeRepository->deleteAllForUser($user);
        $this->authController->invalidate2FAToken($twoFactorToken);

        // Déléguer l'émission du JWT au AuthController
        return $this->authController->issueJwtResponse($user, $request);
    }
}
