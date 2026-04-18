<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\RefreshToken;
use App\Entity\TwoFactorCode;
use App\Repository\TwoFactorCodeRepository;
use App\Service\SMSService;
use App\Service\SmsSender;
use App\Service\SecurityLogger;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Psr\Cache\CacheItemPoolInterface;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    /**
     * Politique de mot de passe centralisée (OWASP)
     * Min 8 chars, 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial
     */
    private function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if (strlen($password) > 128) {
            return 'Le mot de passe ne doit pas dépasser 128 caractères.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une lettre majuscule.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une lettre minuscule.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Le mot de passe doit contenir au moins un chiffre.';
        }
        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?~`]/', $password)) {
            return 'Le mot de passe doit contenir au moins un caractère spécial (!@#$%^&*...).';
        }
        // Vérifier les mots de passe trop communs
        $commonPasswords = ['Password1!', 'Qwerty123!', 'Azerty123!', 'Admin123!', 'Welcome1!', 'Passw0rd!'];
        if (in_array($password, $commonPasswords, true)) {
            return 'Ce mot de passe est trop courant. Choisissez un mot de passe plus unique.';
        }
        return null;
    }

    /**
     * Nettoyer et normaliser l'email
     */
    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Nettoyer les entrées texte (anti-XSS)
     */
    private function sanitizeInput(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * Vérifier le token reCAPTCHA auprès de Google
     */
    private function verifyCaptcha(string $token, string $clientIp): bool
    {
        $secretKey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? '';
        if (empty($secretKey)) {
            // Si pas de clé configurée, logger et laisser passer (dev uniquement)
            if (($_ENV['APP_ENV'] ?? 'prod') === 'dev') {
                error_log('⚠️ RECAPTCHA_SECRET_KEY non configuré — reCAPTCHA ignoré en dev');
                return true;
            }
            return false;
        }

        // Bloquer la clé de test Google en production — accepte n'importe quel token
        $isTestKey = in_array($secretKey, [
            '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe', // test secret Google
            '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI', // test site key Google
        ], true);
        if ($isTestKey && ($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
            error_log('🚨 RECAPTCHA_SECRET_KEY est la clé de TEST Google — reCAPTCHA refusé en production');
            return false;
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $clientIp,
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 10,
            ],
        ];

        try {
            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            if ($result === false) {
                error_log('❌ reCAPTCHA verification failed: no response from Google');
                return false;
            }

            $responseData = json_decode($result, true);
            if (!$responseData) {
                error_log('❌ reCAPTCHA verification failed: invalid JSON response');
                return false;
            }

            if (!($responseData['success'] ?? false)) {
                $errorCodes = $responseData['error-codes'] ?? [];
                error_log('❌ reCAPTCHA verification failed: ' . implode(', ', $errorCodes));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            error_log('❌ reCAPTCHA verification exception: ' . $e->getMessage());
            return false;
        }
    }

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private SMSService $smsService,
        private SmsSender $smsSender,
        private SecurityLogger $securityLogger,
        private RequestStack $requestStack,
        private CacheItemPoolInterface $cache,
        private JWTTokenManagerInterface $jwtManager,
        private TwoFactorCodeRepository $twoFactorCodeRepository,
        private ?EmailService $emailService = null
    ) {
    }

#[Route('/send-otp', name: 'auth_send_otp', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function sendOTP(Request $request, RateLimiterFactory $loginLimiter): JsonResponse
    {
        $limiter = $loginLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives. Réessayez dans 10 minutes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $phone = $data['phone'] ?? null;

        if (!$phone || !$this->smsService->validatePhoneNumber($phone)) {
            return $this->json(['error' => 'Numéro de téléphone invalide'], Response::HTTP_BAD_REQUEST);
        }

        $code = $this->smsService->generateOTP();
        
        // Stocker dans le cache (CORRECTEMENT)
        $cacheKey = "otp_{$phone}";
        $cacheItem = $this->cache->getItem($cacheKey);
        $cacheItem->set($code);
        $cacheItem->expiresAfter(300); // 5 minutes
        $this->cache->save($cacheItem);

        // Log du code OTP en mode développement
        if ($_ENV['APP_ENV'] === 'dev') {
            error_log("\n========================================");
            error_log("📱 OTP CODE FOR {$phone}");
            error_log("🔐 CODE: {$code}");
            error_log("⏰ Valid for 5 minutes");
            error_log("✅ Stored in cache: {$cacheKey}");
            error_log("========================================\n");
        }

        $sent = $this->smsService->sendOTP($phone, $code);

        if (!$sent) {
            return $this->json([
                'error' => 'Échec de l\'envoi du SMS. Veuillez réessayer.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => 'Code envoyé par SMS',
            'expiresIn' => 300
        ]);
    }

    #[Route('/verify-otp', name: 'auth_verify_otp', methods: ['POST'])]
    public function verifyOTP(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $phone = $data['phone'] ?? null;
        $code = $data['code'] ?? null;

        // Log pour debug
        if ($_ENV['APP_ENV'] === 'dev') {
            error_log("🔍 Verify OTP - Phone: {$phone}, Code: {$code}");
        }

        if (!$phone || !$code) {
            return $this->json(['error' => 'Téléphone et code requis'], Response::HTTP_BAD_REQUEST);
        }

        $cacheKey = "otp_{$phone}";
        $cacheItem = $this->cache->getItem($cacheKey);
        $storedCode = $cacheItem->isHit() ? $cacheItem->get() : null;

        // Log pour debug
        if ($_ENV['APP_ENV'] === 'dev') {
            error_log("🔍 Cache Key: {$cacheKey}");
            error_log("🔍 Stored Code: " . ($storedCode ?? 'NULL'));
            error_log("🔍 Cache Hit: " . ($cacheItem->isHit() ? 'YES' : 'NO'));
        }

        if (!$storedCode) {
            return $this->json(['error' => 'Code expiré ou introuvable'], Response::HTTP_BAD_REQUEST);
        }

        if ($storedCode !== $code) {
            error_log("❌ Code mismatch - Expected: {$storedCode}, Got: {$code}");
            return $this->json(['error' => 'Code incorrect'], Response::HTTP_BAD_REQUEST);
        }

        // Supprimer l'OTP et marquer le téléphone comme vérifié
        $this->cache->deleteItem($cacheKey);
        
        $verifiedKey = "phone_verified_{$phone}";
        $verifiedItem = $this->cache->getItem($verifiedKey);
        $verifiedItem->set(true);
        $verifiedItem->expiresAfter(3600); // 1 heure pour compléter l'inscription
        $this->cache->save($verifiedItem);

        if ($_ENV['APP_ENV'] === 'dev') {
            error_log("✅ Phone verified: {$phone}");
        }

        return $this->json(['message' => 'Téléphone vérifié avec succès']);
    }

    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function login(Request $request, RateLimiterFactory $loginLimiter): JsonResponse
    {
        $limiter = $loginLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        
        $username = $data['username'] ?? $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (!$username || !$password) {
            return $this->json([
                'error' => 'Email et mot de passe requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Normaliser l'email
        $username = $this->normalizeEmail($username);

        // Chercher l'utilisateur par email
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $username]);

        if (!$user) {
            // Timing-safe : simuler un hash pour éviter l'énumération par timing
            password_hash('dummy_password_timing_safe', PASSWORD_BCRYPT);
            $this->securityLogger->logFailedLogin($username, $request);
            return $this->json([
                'error' => 'Identifiant ou mot de passe incorrect'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Bloquer la connexion tant que l'email n'est pas vérifié
        if (!$user->isEmailVerified()) {
            return $this->json([
                'error' => 'Veuillez vérifier votre adresse email avant de vous connecter.',
                'emailNotVerified' => true,
                'email' => $user->getEmail(),
            ], Response::HTTP_FORBIDDEN);
        }

        // Vérifier si le compte est banni ou suspendu
        if ($user->isIsBanned()) {
            $this->securityLogger->logFailedLogin($username, $request);
            return $this->json([
                'error' => 'Ce compte a été suspendu. Contactez le support.'
            ], Response::HTTP_FORBIDDEN);
        }
        if ($user->isIsSuspended()) {
            $bannedUntil = $user->getBannedUntil();
            if ($bannedUntil && $bannedUntil > new \DateTime()) {
                $this->securityLogger->logFailedLogin($username, $request);
                return $this->json([
                    'error' => 'Ce compte est temporairement suspendu. Réessayez plus tard.'
                ], Response::HTTP_FORBIDDEN);
            }
            // Suspension expirée → la lever automatiquement
            $user->setIsSuspended(false);
            $user->setBannedUntil(null);
            $this->entityManager->flush();
        }

        // Vérifier le mot de passe
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->securityLogger->logFailedLogin($username, $request);
            return $this->json([
                'error' => 'Identifiant ou mot de passe incorrect'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // ─── 2FA : Générer OTP et envoyer par email ───
        return $this->json($this->createTwoFactorChallengePayload($user, $request));
    }

    private function createTwoFactorChallengePayload(User $user, Request $request, ?string $message = null): array
    {
        // Supprimer les anciens codes 2FA de cet utilisateur
        $this->twoFactorCodeRepository->deleteAllForUser($user);

        // Générer un code OTP sécurisé à 6 chiffres
        $otpPlain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Hasher le code (jamais stocké en clair)
        $otpHash = password_hash($otpPlain, PASSWORD_BCRYPT);

        // Créer l'entité TwoFactorCode
        $twoFactorCode = new TwoFactorCode();
        $twoFactorCode->setUser($user);
        $twoFactorCode->setCodeHash($otpHash);
        $twoFactorCode->setExpiresAt(new \DateTime('+5 minutes'));
        $twoFactorCode->setAttempts(0);

        $this->entityManager->persist($twoFactorCode);
        $this->entityManager->flush();

        // Envoyer le code par email
        $emailSent = false;
        if ($this->emailService !== null) {
            try {
                $emailSent = $this->emailService->send2FAEmail($user, $otpPlain);
            } catch (\Exception $e) {
                error_log('Erreur envoi email 2FA: ' . $e->getMessage());
            }
        }

        if (!$emailSent) {
            error_log("WARNING: 2FA email could not be sent for {$user->getEmail()}");
        }

        $isDev = (($_ENV['APP_ENV'] ?? 'prod') === 'dev');

        // Log en mode dev uniquement (JAMAIS en prod)
        if ($isDev) {
            error_log("🔐 2FA CODE for {$user->getEmail()}: {$otpPlain}");
        }

        // Générer un token opaque signé (évite d'exposer l'user_id)
        $twoFactorToken = $this->generate2FAToken($user->getId(), $request->getClientIp());

        // Stocker dans le cache : token → userId + IP (binding IP strict)
        $pendingKey = '2fa_pending_' . hash('sha256', $twoFactorToken);
        $cacheItem = $this->cache->getItem($pendingKey);
        $cacheItem->set([
            'user_id' => $user->getId(),
            'ip' => $request->getClientIp(),
            'created_at' => time(),
        ]);
        $cacheItem->expiresAfter(300); // 5 minutes
        $this->cache->save($cacheItem);

        $payload = [
            '2fa_required' => true,
            '2fa_token' => $twoFactorToken,
            'message' => $message ?? ($emailSent ? 'Un code de vérification a été envoyé par email.' : 'Un code de vérification a été généré.'),
            'expires_in' => 300,
        ];

        // En dev, inclure l'OTP en clair dans la réponse si l'email n'a pas pu être envoyé
        if ($isDev && !$emailSent) {
            $payload['dev_otp'] = $otpPlain;
            $payload['message'] = 'DEV MODE — CODE OTP: ' . $otpPlain . ' (email non envoyé car MAILER_DSN non configuré)';
        }

        return $payload;
    }

    /**
     * Générer un token 2FA opaque signé avec HMAC
     * Lie le token à l'userId et à l'IP du client
     */
    private function generate2FAToken(int $userId, ?string $ip): string
    {
        $secret = $_ENV['APP_SECRET'] ?? throw new \RuntimeException('APP_SECRET environment variable is not set');
        $nonce = bin2hex(random_bytes(16));
        $payload = $userId . '|' . ($ip ?? 'unknown') . '|' . $nonce . '|' . time();
        $signature = hash_hmac('sha256', $payload, $secret);
        return base64_encode($payload . '.' . $signature);
    }

    /**
     * Valider un token 2FA et extraire les données du cache
     * Retourne [user_id, ip, created_at] ou null si invalide
     */
    public function validate2FAToken(string $token): ?array
    {
        $pendingKey = '2fa_pending_' . hash('sha256', $token);
        $cacheItem = $this->cache->getItem($pendingKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        return $cacheItem->get();
    }

    /**
     * Supprimer un token 2FA du cache
     */
    public function invalidate2FAToken(string $token): void
    {
        $pendingKey = '2fa_pending_' . hash('sha256', $token);
        $this->cache->deleteItem($pendingKey);
    }

    /**
     * Émettre la réponse JWT classique (utilisé après 2FA ou en fallback)
     */
    public function issueJwtResponse(User $user, Request $request): JsonResponse
    {
        // Générer un JWT token avec Lexik
        $token = $this->jwtManager->create($user);

        // Créer un refresh token
        $refreshToken = new RefreshToken();
        $refreshToken->setUser($user);
        $refreshToken->setIpAddress($request->getClientIp());
        $refreshToken->setUserAgent($request->headers->get('User-Agent'));
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        $this->securityLogger->logLogin($user, $request);

        return $this->json([
            'token' => $token,
            'refreshToken' => $refreshToken->getToken(),
            'expiresIn' => 3600,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'city' => $user->getCity(),
                'country' => $user->getCountry(),
                'bio' => $user->getBio(),
                'profilePicture' => $user->getProfilePicture(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'isLifetimePro' => $user->isLifetimePro(),
                'roles' => $user->getRoles(),
                'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles()),
            ]
        ]);
    }

    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function register(Request $request, RateLimiterFactory $registerLimiter): JsonResponse
    {
        // Rate limiter TOUJOURS actif (même en dev)
        $limiter = $registerLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop d\'inscriptions. Veuillez réessayer après 1 heure.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        // Vérification reCAPTCHA (optionnelle pour les clients mobiles qui n'envoient pas de token)
        $captchaToken = $data['captchaToken'] ?? null;
        if ($captchaToken) {
            $captchaValid = $this->verifyCaptcha($captchaToken, $request->getClientIp());
            if (!$captchaValid) {
                return $this->json([
                    'error' => 'Échec de la vérification reCAPTCHA. Veuillez réessayer.'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Champs requis simplifiés : email, password, firstName, lastName
        $requiredFields = ['email', 'password', 'firstName', 'lastName'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return $this->json([
                    'error' => "Le champ $field est requis"
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Validation du mot de passe via politique centralisée
        $password = $data['password'];
        $passwordError = $this->validatePasswordStrength($password);
        if ($passwordError) {
            return $this->json([
                'error' => 'Mot de passe trop faible',
                'message' => $passwordError
            ], Response::HTTP_BAD_REQUEST);
        }

        // Normaliser l'email
        $data['email'] = $this->normalizeEmail($data['email']);

        // Vérifier si l'email existe déjà
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json([
                'error' => 'Cet email est déjà utilisé',
                'message' => 'Un compte existe déjà avec cet email. Connectez-vous ou utilisez un autre email.'
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email'])
            ->setFirstName($this->sanitizeInput($data['firstName']))
            ->setLastName($this->sanitizeInput($data['lastName']))
            ->setAccountType('FREE')
            ->setIsEmailVerified(false)
            ->setIsPhoneVerified(false);

        // Champs optionnels
        if (isset($data['phone']) && !empty($data['phone'])) {
            $user->setPhone($data['phone']);
        }
        if (isset($data['country']) && !empty($data['country'])) {
            $user->setCountry($data['country']);
        }
        if (isset($data['nationality']) && !empty($data['nationality'])) {
            $user->setNationality($data['nationality']);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()][] = $error->getMessage();
            }
            
            // Log pour debug
            if ($_ENV['APP_ENV'] === 'dev') {
                error_log("❌ Validation errors:");
                error_log(json_encode($errorMessages, JSON_PRETTY_PRINT));
            }
            
            return $this->json([
                'error' => 'Erreur de validation',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Tester la connexion à la base de données avant de persister
            try {
                $this->entityManager->getConnection()->connect();
            } catch (\Exception $connException) {
                error_log("❌ Database connection test failed: " . $connException->getMessage());
                return $this->json([
                    'error' => 'Base de données non disponible',
                    'message' => 'La base de données n\'est pas accessible. Veuillez vérifier que PostgreSQL est démarré et que la configuration est correcte.'
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->securityLogger->logRegister($user, $request);

            // Générer un token de vérification d'email
            $verificationToken = bin2hex(random_bytes(32));
            // Utiliser MD5 de l'email pour éviter les caractères spéciaux (@) dans la clé de cache
            $cacheKey = "email_verification_" . md5($user->getEmail());
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($verificationToken);
            $cacheItem->expiresAfter(86400); // 24 heures
            $this->cache->save($cacheItem);

            // Stocker également une correspondance token -> email (pour les liens qui ne contiennent que le token)
            $tokenKey = 'email_verification_token_' . hash('sha256', $verificationToken);
            $tokenItem = $this->cache->getItem($tokenKey);
            $tokenItem->set($user->getEmail());
            $tokenItem->expiresAfter(86400); // 24 heures
            $this->cache->save($tokenItem);

            $frontendUrl = rtrim((string) ($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173'), '/');
            $verificationUrl = $frontendUrl . '/verify-email?token=' . $verificationToken;

            // Envoyer l'email de bienvenue avec le lien de vérification
            $welcomeEmailSent = false;
            error_log("=== DEBUG EMAIL ===");
            error_log("EmailService null? " . ($this->emailService === null ? 'OUI' : 'NON'));
            if ($this->emailService !== null) {
                error_log("EmailService->isConfigured()? " . ($this->emailService->isConfigured() ? 'OUI' : 'NON'));
                try {
                    // Éviter le transport null://null (ne livre rien)
                    if ($this->emailService->isConfigured()) {
                        error_log("Tentative envoi email à: " . $user->getEmail());
                        $welcomeEmailSent = $this->emailService->sendWelcomeEmail($user, $verificationToken);
                        error_log("Résultat envoi: " . ($welcomeEmailSent ? 'SUCCÈS' : 'ÉCHEC'));
                    } else {
                        error_log("MAILER non configuré - email non envoyé");
                    }
                } catch (\Exception $e) {
                    // Logger l'erreur mais ne pas faire échouer l'inscription
                    error_log("Erreur envoi email bienvenue: " . $e->getMessage());
                }
            } else {
                // Logger si EmailService n'est pas configuré
                error_log("EmailService non configuré - email de bienvenue non envoyé");
            }

            $isDev = (($_ENV['APP_ENV'] ?? 'prod') === 'dev');
            $message = $welcomeEmailSent
                ? 'Inscription réussie. Un email de vérification a été envoyé à votre adresse.'
                : 'Inscription réussie. Veuillez vérifier votre email pour activer votre compte.';

            $response = [
                'message' => $message,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'accountType' => $user->getAccountType(),
                    'isEmailVerified' => false,
                ]
            ];

            // Fallback DEV uniquement : si l'email n'a pas pu être envoyé, renvoyer le lien
            if ($isDev && !$welcomeEmailSent) {
                $response['message'] = 'Inscription réussie. EMAIL NON ENVOYÉ (MAILER_DSN non configuré). Ouvrez le lien de vérification ci-dessous pour continuer.';
                $response['verificationUrl'] = $verificationUrl;
            }

            return $this->json($response, Response::HTTP_CREATED);
        } catch (ConnectionException $e) {
            // Erreur de connexion à la base de données
            error_log("❌ Database connection error: " . $e->getMessage());
            return $this->json([
                'error' => 'Erreur de connexion à la base de données',
                'message' => 'La base de données n\'est pas accessible. Veuillez vérifier la configuration.'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (DriverException $e) {
            // Erreur de driver de base de données
            error_log("❌ Database driver error: " . $e->getMessage());
            return $this->json([
                'error' => 'Erreur de base de données',
                'message' => 'Une erreur est survenue avec la base de données. Veuillez réessayer plus tard.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            // Log pour debug
            error_log("❌ Registration exception:");
            error_log("Message: " . $e->getMessage());
            error_log("File: " . $e->getFile() . ":" . $e->getLine());
            if ($_ENV['APP_ENV'] === 'dev') {
                error_log("Trace: " . $e->getTraceAsString());
            }
            
            // Vérifier si c'est une erreur de contrainte unique
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'UNIQ_IDENTIFIER_EMAIL') || 
                str_contains($errorMessage, 'duplicate key value') ||
                str_contains($errorMessage, 'already exists') ||
                str_contains($errorMessage, 'UNIQUE constraint')) {
                return $this->json([
                    'error' => 'Cet email est déjà utilisé',
                    'message' => 'Un compte existe déjà avec cet email. Connectez-vous ou utilisez un autre email.'
                ], Response::HTTP_CONFLICT);
            }
            
            // Vérifier si c'est une erreur de connexion à la base de données
            if (str_contains($errorMessage, 'Connection refused') ||
                str_contains($errorMessage, 'could not connect') ||
                str_contains($errorMessage, 'No connection') ||
                (str_contains($errorMessage, 'SQLSTATE') && str_contains($errorMessage, '08006')) ||
                str_contains($errorMessage, 'SQLSTATE[08006]') ||
                str_contains($errorMessage, 'could not find driver') ||
                str_contains($errorMessage, 'PDOException')) {
                return $this->json([
                    'error' => 'Base de données non disponible',
                    'message' => 'La base de données n\'est pas accessible. Veuillez vérifier que PostgreSQL est démarré et correctement configuré. Erreur: ' . ($_ENV['APP_ENV'] === 'dev' ? $errorMessage : 'Connexion impossible')
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            
            // En mode dev, retourner le message d'erreur complet pour faciliter le debug
            $devMessage = $_ENV['APP_ENV'] === 'dev' 
                ? $errorMessage . ' (File: ' . $e->getFile() . ':' . $e->getLine() . ')' 
                : 'Une erreur est survenue. Veuillez réessayer.';
            
            return $this->json([
                'error' => 'Erreur lors de l\'inscription',
                'message' => $devMessage
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'whatsappPhone' => $user->getWhatsappPhone(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
            'bio' => $user->getBio(),
            'roles' => $user->getRoles(),
            'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles()),
            'accountType' => $user->getAccountType(),
            'isPro' => $user->isPro(),
            'isLifetimePro' => $user->isLifetimePro(),
            'country' => $user->getCountry(),
            'nationality' => $user->getNationality(),
            'city' => $user->getCity(),
            'profilePicture' => $user->getProfilePicture(),
            'isEmailVerified' => $user->isEmailVerified(),
            'isPhoneVerified' => $user->isPhoneVerified(),
            'isVerified' => $user->isIdentityVerified(),
            'verificationBadges' => $user->getVerificationBadges(),
            'verificationStatus' => $user->getVerificationStatus(),
            'verificationCategory' => $user->getVerificationCategory(),
            'subscriptionExpiresAt' => $user->getSubscriptionExpiresAt()?->format('c'),
            'subscriptionStartDate' => $user->getSubscriptionStartDate()?->format('c'),
            'createdAt' => $user->getCreatedAt()?->format('c'),
        ]);
    }

    #[Route('/verify-email', name: 'auth_verify_email', methods: ['POST', 'GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function verifyEmail(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        // Support GET pour les liens dans les emails
        $token = $request->query->get('token') ?? ($payload['token'] ?? null);
        $email = $request->query->get('email') ?? ($payload['email'] ?? null);

        // Si token fourni, vérifier depuis le cache
        if ($token) {
            // Si l'email n'est pas fourni dans la requête, le retrouver à partir du token
            if (!$email) {
                $tokenKey = 'email_verification_token_' . hash('sha256', $token);
                $tokenItem = $this->cache->getItem($tokenKey);
                $email = $tokenItem->isHit() ? $tokenItem->get() : null;
            }

            if (!$email) {
                return $this->json(['error' => 'Token invalide ou expiré'], Response::HTTP_BAD_REQUEST);
            }

            // Utiliser MD5 de l'email pour éviter les caractères spéciaux (@) dans la clé de cache
            $emailKey = 'email_verification_' . md5($email);
            $emailItem = $this->cache->getItem($emailKey);
            $storedToken = $emailItem->isHit() ? $emailItem->get() : null;

            if (!$storedToken || !hash_equals((string) $storedToken, (string) $token)) {
                return $this->json(['error' => 'Token invalide ou expiré'], Response::HTTP_BAD_REQUEST);
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $this->normalizeEmail((string) $email)]);
            if (!$user) {
                return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
            }

            if (!$user->isEmailVerified()) {
                $user->setIsEmailVerified(true);
                $this->entityManager->flush();
            }

            // Supprimer le token du cache (les deux sens)
            $this->cache->deleteItem($emailKey);
            $this->cache->deleteItem('email_verification_token_' . hash('sha256', $token));

            // Envoyer un email de confirmation
            if ($this->emailService !== null) {
                try {
                    $this->emailService->sendEmailVerifiedConfirmation($user);
                } catch (\Exception $e) {
                    error_log('Erreur envoi email confirmation: ' . $e->getMessage());
                }
            }

            // Après vérification d'email, déclencher l'OTP (2FA) : accès seulement après saisie du code
            return $this->json(
                $this->createTwoFactorChallengePayload(
                    $user,
                    $request,
                    'Email vérifié. Un code OTP a été envoyé par email. Entrez-le pour accéder à votre compte.'
                )
            );
        }

        // Demande publique de renvoi d'email (si email fourni)
        if ($email) {
            $email = $this->normalizeEmail((string) $email);
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user) {
                // Message neutre pour éviter l'énumération d'emails
                return $this->json(['message' => 'Si un compte existe, un email de vérification a été envoyé.']);
            }
            if ($user->isEmailVerified()) {
                return $this->json(['message' => 'Cet email est déjà vérifié.']);
            }

            $verificationToken = bin2hex(random_bytes(32));
            $emailKey = 'email_verification_' . md5($user->getEmail());
            $emailItem = $this->cache->getItem($emailKey);
            $emailItem->set($verificationToken);
            $emailItem->expiresAfter(86400);
            $this->cache->save($emailItem);

            $tokenKey = 'email_verification_token_' . hash('sha256', $verificationToken);
            $tokenItem = $this->cache->getItem($tokenKey);
            $tokenItem->set($user->getEmail());
            $tokenItem->expiresAfter(86400);
            $this->cache->save($tokenItem);

            if ($this->emailService !== null) {
                try {
                    $this->emailService->sendEmailVerification($user, $verificationToken);
                } catch (\Exception $e) {
                    error_log('Erreur envoi email vérification: ' . $e->getMessage());
                }
            }

            return $this->json(['message' => 'Si un compte existe, un email de vérification a été envoyé.']);
        }

        // Si pas de token, vérifier l'utilisateur connecté
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Générer un nouveau token et renvoyer l'email
        $verificationToken = bin2hex(random_bytes(32));
        // Utiliser MD5 de l'email pour éviter les caractères spéciaux (@) dans la clé de cache
        $cacheKey = "email_verification_" . md5($user->getEmail());
        $cacheItem = $this->cache->getItem($cacheKey);
        $cacheItem->set($verificationToken);
        $cacheItem->expiresAfter(86400); // 24 heures
        $this->cache->save($cacheItem);

        $tokenKey = 'email_verification_token_' . hash('sha256', $verificationToken);
        $tokenItem = $this->cache->getItem($tokenKey);
        $tokenItem->set($user->getEmail());
        $tokenItem->expiresAfter(86400); // 24 heures
        $this->cache->save($tokenItem);

        if ($this->emailService !== null) {
            try {
                $this->emailService->sendEmailVerification($user, $verificationToken);
            } catch (\Exception $e) {
                error_log("Erreur envoi email vérification: " . $e->getMessage());
            }
        }

        return $this->json(['message' => 'Un nouvel email de vérification a été envoyé']);
    }

    #[Route('/verify-phone', name: 'auth_verify_phone', methods: ['POST'])]
    public function verifyPhone(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;

        if (!$code) {
            return $this->json(['error' => 'Code requis'], Response::HTTP_BAD_REQUEST);
        }

        $cacheKey = "otp_{$user->getPhone()}";
        $cacheItem = $this->cache->getItem($cacheKey);
        $storedCode = $cacheItem->isHit() ? $cacheItem->get() : null;

        if (!$storedCode || $storedCode !== $code) {
            return $this->json(['error' => 'Code invalide ou expiré'], Response::HTTP_BAD_REQUEST);
        }

        $user->setIsPhoneVerified(true);
        $this->entityManager->flush();

        $this->cache->deleteItem($cacheKey);

        return $this->json(['message' => 'Téléphone vérifié avec succès']);
    }

    #[Route('/update-profile', name: 'auth_update_profile', methods: ['PUT', 'PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Mettre à jour les champs optionnels
        if (isset($data['bio'])) {
            // Limiter la taille du bio à 1000 caractères + sanitize
            $bio = mb_substr(trim($data['bio']), 0, 1000);
            $user->setBio($bio);
        }
        if (isset($data['phone'])) {
            // Valider le format téléphone (chiffres, +, espaces, tirets)
            $phone = preg_replace('/[^\d+\-\s()]/', '', $data['phone']);
            $user->setPhone($phone);
        }
        if (isset($data['whatsapp']) || isset($data['whatsappPhone'])) {
            $wp = $data['whatsapp'] ?? $data['whatsappPhone'];
            $wp = preg_replace('/[^\d+\-\s()]/', '', $wp);
            $user->setWhatsappPhone($wp);
        }
        if (isset($data['country'])) {
            $user->setCountry($this->sanitizeInput(mb_substr($data['country'], 0, 100)));
        }
        if (isset($data['city'])) {
            $user->setCity($this->sanitizeInput(mb_substr($data['city'], 0, 100)));
        }
        if (isset($data['firstName'])) {
            $user->setFirstName($this->sanitizeInput(mb_substr($data['firstName'], 0, 100)));
        }
        if (isset($data['lastName'])) {
            $user->setLastName($this->sanitizeInput(mb_substr($data['lastName'], 0, 100)));
        }
        if (isset($data['profilePicture'])) {
            // Valider que c'est une URL ou un chemin d'image valide (pas de JS inline)
            $pic = trim($data['profilePicture']);
            if ($pic && !preg_match('/^(https?:\/\/|\/)/', $pic)) {
                return $this->json(['error' => 'URL d\'image invalide'], Response::HTTP_BAD_REQUEST);
            }
            $user->setProfilePicture($pic);
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()][] = $error->getMessage();
            }
            
            return $this->json([
                'error' => 'Erreur de validation',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->flush();

            return $this->json([
                'message' => 'Profil mis à jour avec succès',
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'phone' => $user->getPhone(),
                    'bio' => $user->getBio(),
                    'whatsappPhone' => $user->getWhatsappPhone(),
                    'country' => $user->getCountry(),
                    'city' => $user->getCity(),
                    'profilePicture' => $user->getProfilePicture(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la mise à jour du profil',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function forgotPassword(Request $request, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        // Rate limiter dédié au password reset (séparé du login)
        $limiter = $passwordResetLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives. Réessayez dans 15 minutes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $email = isset($data['email']) ? $this->normalizeEmail($data['email']) : null;

        if (!$email) {
            return $this->json(['error' => 'Email requis'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        // Toujours retourner succès pour ne pas révéler si l'email existe
        if (!$user) {
            return $this->json(['message' => 'Si cet email existe, un code de réinitialisation a été envoyé', 'expiresIn' => 900]);
        }

        // Générer un code de réinitialisation (6 chiffres)
        $resetCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Générer un token opaque (pour le lien email — ne contient ni l'email ni le code)
        $resetToken = bin2hex(random_bytes(32));
        
        // Hasher le code avant de le stocker (bcrypt)
        $hashedCode = password_hash($resetCode, PASSWORD_BCRYPT);
        
        // Données communes
        $resetData = [
            'code_hash' => $hashedCode,
            'email' => $email,
            'attempts' => 0,
            'ip' => $request->getClientIp(),
            'created_at' => time(),
        ];
        
        // Stocker par token opaque (pour le lien email)
        $tokenCacheKey = "password_reset_token_" . hash('sha256', $resetToken);
        $tokenCacheItem = $this->cache->getItem($tokenCacheKey);
        $tokenCacheItem->set($resetData);
        $tokenCacheItem->expiresAfter(900); // 15 minutes
        $this->cache->save($tokenCacheItem);
        
        // Stocker aussi par email (pour la saisie manuelle sur la page)
        $emailCacheKey = "password_reset_" . hash('sha256', $email);
        $emailCacheItem = $this->cache->getItem($emailCacheKey);
        $emailCacheItem->set(array_merge($resetData, ['token_key' => $tokenCacheKey]));
        $emailCacheItem->expiresAfter(900);
        $this->cache->save($emailCacheItem);

        // En mode dev, afficher le code UNIQUEMENT dans les logs serveur
        if ($_ENV['APP_ENV'] === 'dev') {
            error_log("\n========================================");
            error_log("PASSWORD RESET CODE FOR {$email}");
            error_log("CODE: {$resetCode}");
            error_log("TOKEN: {$resetToken}");
            error_log("Valid for 15 minutes");
            error_log("========================================\n");
        }

        // Envoyer l'email de réinitialisation (avec le token opaque, PAS l'email/code)
        $emailSent = false;
        if ($this->emailService !== null) {
            try {
                $emailSent = $this->emailService->sendPasswordResetEmail($user, $resetCode, $resetToken);
            } catch (\Exception $e) {
                error_log("Erreur envoi email reset password: " . $e->getMessage());
            } catch (\Throwable $e) {
                error_log("Erreur fatale envoi email reset password: " . $e->getMessage());
            }
        }

        // Envoyer aussi par SMS si le user a un téléphone
        $smsSent = false;
        if ($user->getPhone()) {
            try {
                $this->smsService->sendOTP($user->getPhone(), $resetCode);
                $smsSent = true;
            } catch (\Exception $e) {
                error_log("Erreur envoi SMS reset password: " . $e->getMessage());
            }
        }

        // Construire le message de réponse (ne JAMAIS retourner le code)
        $message = 'Si cet email existe, un code de réinitialisation a été envoyé';
        $methods = [];
        if ($emailSent) $methods[] = 'email';
        if ($smsSent) $methods[] = 'SMS';
        
        if (!empty($methods)) {
            $message .= ' par ' . implode(' et ', $methods);
        }

        return $this->json([
            'message' => $message,
            'expiresIn' => 900
        ]);
    }

    #[Route('/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function resetPassword(Request $request, RateLimiterFactory $passwordResetLimiter): JsonResponse
    {
        $limiter = $passwordResetLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives. Réessayez dans 15 minutes.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $resetToken = $data['token'] ?? null;
        $email = $data['email'] ?? null;
        $code = $data['code'] ?? null;
        $newPassword = $data['password'] ?? $data['newPassword'] ?? null;

        if (!$newPassword) {
            return $this->json(['error' => 'Nouveau mot de passe requis'], Response::HTTP_BAD_REQUEST);
        }

        // Le code est obligatoire uniquement en flux manuel (par email, sans token)
        if (!$resetToken && !$code) {
            return $this->json(['error' => 'Code requis'], Response::HTTP_BAD_REQUEST);
        }

        if (!$resetToken && !$email) {
            return $this->json(['error' => 'Token ou email requis'], Response::HTTP_BAD_REQUEST);
        }

        // Validation du mot de passe via politique centralisée
        $passwordError = $this->validatePasswordStrength($newPassword);
        if ($passwordError) {
            return $this->json(['error' => $passwordError], Response::HTTP_BAD_REQUEST);
        }

        // Normaliser l'email si fourni
        if ($email) {
            $email = $this->normalizeEmail($email);
        }

        // Résoudre la session de reset : via token opaque (lien email) ou via email (saisie manuelle)
        if ($resetToken) {
            $cacheKey = "password_reset_token_" . hash('sha256', $resetToken);
        } else {
            $cacheKey = "password_reset_" . hash('sha256', $email);
        }

        $cacheItem = $this->cache->getItem($cacheKey);
        $storedData = $cacheItem->isHit() ? $cacheItem->get() : null;

        if (!$storedData || !is_array($storedData)) {
            return $this->json(['error' => 'Code invalide ou expiré'], Response::HTTP_BAD_REQUEST);
        }

        // Récupérer l'email depuis les données stockées
        $resolvedEmail = $storedData['email'] ?? $email;

        // Vérifier le nombre de tentatives (max 5)
        $attempts = $storedData['attempts'] ?? 0;
        if ($attempts >= 5) {
            $this->cache->deleteItem($cacheKey);
            // Aussi supprimer l'autre clé (token ou email)
            if ($resetToken && isset($storedData['email'])) {
                $this->cache->deleteItem("password_reset_" . hash('sha256', $storedData['email']));
            }
            error_log("SECURITY: Password reset max attempts exceeded for {$resolvedEmail} from IP " . $request->getClientIp());
            return $this->json([
                'error' => 'Trop de tentatives. Veuillez demander un nouveau code.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Vérifier le code uniquement si fourni (flux manuel par email)
        // Quand le token opaque est utilisé, il prouve déjà l'accès à l'email
        if ($code) {
            // Comparaison timing-safe via password_verify (bcrypt)
            if (!password_verify($code, $storedData['code_hash'])) {
                $storedData['attempts'] = $attempts + 1;
                $cacheItem->set($storedData);
                $this->cache->save($cacheItem);

                $remaining = 5 - $storedData['attempts'];
                return $this->json([
                    'error' => 'Code invalide ou expiré',
                    'attempts_remaining' => $remaining
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $resolvedEmail]);

        if (!$user) {
            $this->cache->deleteItem($cacheKey);
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Mettre à jour le mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        // Supprimer les deux clés cache (token + email)
        $this->cache->deleteItem($cacheKey);
        if ($resetToken && isset($storedData['email'])) {
            $this->cache->deleteItem("password_reset_" . hash('sha256', $storedData['email']));
        } elseif (!$resetToken && isset($storedData['token_key'])) {
            $this->cache->deleteItem($storedData['token_key']);
        }

        // Log de sécurité
        error_log("Password reset successful for: {$resolvedEmail} from IP: " . $request->getClientIp());

        // Envoyer un email de confirmation
        if ($this->emailService !== null) {
            try {
                $this->emailService->sendPasswordChangedConfirmation($user);
            } catch (\Exception $e) {
                error_log("Erreur envoi email confirmation reset: " . $e->getMessage());
            }
        }

        return $this->json(['message' => 'Mot de passe réinitialisé avec succès']);
    }

    #[Route('/change-password', name: 'auth_change_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $currentPassword = $data['currentPassword'] ?? null;
        $newPassword = $data['newPassword'] ?? null;

        if (!$currentPassword || !$newPassword) {
            return $this->json(['error' => 'Mot de passe actuel et nouveau mot de passe requis'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier le mot de passe actuel
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['error' => 'Mot de passe actuel incorrect'], Response::HTTP_BAD_REQUEST);
        }

        // Valider le nouveau mot de passe via politique centralisée
        $passwordError = $this->validatePasswordStrength($newPassword);
        if ($passwordError) {
            return $this->json(['error' => $passwordError], Response::HTTP_BAD_REQUEST);
        }

        // Empêcher la réutilisation du même mot de passe
        if ($this->passwordHasher->isPasswordValid($user, $newPassword)) {
            return $this->json(['error' => 'Le nouveau mot de passe doit être différent de l\'ancien'], Response::HTTP_BAD_REQUEST);
        }

        // Mettre à jour le mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        // Envoyer un email de confirmation
        if ($this->emailService !== null) {
            try {
                $this->emailService->sendPasswordChangedConfirmation($user);
            } catch (\Exception $e) {
                error_log("Erreur envoi email confirmation changement: " . $e->getMessage());
            }
        }

        // Logger le changement de mot de passe
        try {
            $this->securityLogger->logPasswordChange($user, $request);
        } catch (\Exception $e) {
            // Ignorer les erreurs de logging
        }

        return $this->json(['message' => 'Mot de passe modifié avec succès']);
    }

    #[Route('/refresh-token', name: 'auth_refresh_token', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function refreshToken(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refreshToken'] ?? $data['refresh_token'] ?? null;

        if (!$refreshTokenString) {
            return $this->json(['error' => 'Refresh token requis'], Response::HTTP_BAD_REQUEST);
        }

        // Chercher le refresh token
        $refreshToken = $this->entityManager->getRepository(RefreshToken::class)
            ->findOneBy(['token' => $refreshTokenString]);

        if (!$refreshToken) {
            return $this->json(['error' => 'Refresh token invalide'], Response::HTTP_UNAUTHORIZED);
        }

        // Vérifier si expiré
        if ($refreshToken->isExpired()) {
            $this->entityManager->remove($refreshToken);
            $this->entityManager->flush();
            return $this->json(['error' => 'Refresh token expiré'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $refreshToken->getUser();

        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier si le compte est banni ou suspendu (prévient l'usage d'un token volé après ban)
        if ($user->isIsBanned()) {
            $this->entityManager->remove($refreshToken);
            $this->entityManager->flush();
            return $this->json(['error' => 'Ce compte a été suspendu'], Response::HTTP_FORBIDDEN);
        }
        if ($user->isIsSuspended()) {
            $bannedUntil = $user->getBannedUntil();
            if ($bannedUntil && $bannedUntil > new \DateTime()) {
                $this->entityManager->remove($refreshToken);
                $this->entityManager->flush();
                return $this->json(['error' => 'Ce compte est temporairement suspendu'], Response::HTTP_FORBIDDEN);
            }
        }

        // Générer un nouveau JWT
        $newToken = $this->jwtManager->create($user);

        // Optionnel : Générer un nouveau refresh token (rotation)
        $newRefreshToken = new RefreshToken();
        $newRefreshToken->setUser($user);
        $newRefreshToken->setIpAddress($request->getClientIp());
        $newRefreshToken->setUserAgent($request->headers->get('User-Agent'));

        // Supprimer l'ancien refresh token
        $this->entityManager->remove($refreshToken);
        $this->entityManager->persist($newRefreshToken);
        $this->entityManager->flush();

        return $this->json([
            'token' => $newToken,
            'refreshToken' => $newRefreshToken->getToken(),
            'expiresIn' => 3600, // 1 heure pour le JWT
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
            ]
        ]);
    }

    /**
     * Logout : invalide le refresh token côté serveur
     */
    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refreshToken'] ?? $data['refresh_token'] ?? null;

        if ($refreshTokenString) {
            $refreshToken = $this->entityManager->getRepository(RefreshToken::class)
                ->findOneBy(['token' => $refreshTokenString]);
            if ($refreshToken) {
                $this->entityManager->remove($refreshToken);
                $this->entityManager->flush();
            }
        }

        return $this->json(['message' => 'Déconnecté avec succès']);
    }

    /**
     * Liste des appareils connectés (sessions actives via refresh tokens)
     */
    #[Route('/devices', name: 'auth_devices_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function listDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $currentUA = $request->headers->get('User-Agent', '');

        $tokens = $this->entityManager->getRepository(RefreshToken::class)
            ->createQueryBuilder('rt')
            ->where('rt.user = :user')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('rt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $devices = [];
        foreach ($tokens as $rt) {
            $ua = $rt->getUserAgent() ?? '';
            $devices[] = [
                'id'        => $rt->getId(),
                'userAgent' => $ua,
                'ipAddress' => $rt->getIpAddress(),
                'createdAt' => $rt->getCreatedAt()?->format('c'),
                'expiresAt' => $rt->getExpiresAt()?->format('c'),
                'isCurrent' => $this->isSameDevice($ua, $currentUA),
                'device'    => $this->parseUserAgent($ua),
            ];
        }

        return $this->json(['devices' => $devices]);
    }

    /**
     * Révoquer une session (supprimer un refresh token)
     */
    #[Route('/devices/{id}', name: 'auth_devices_revoke', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function revokeDevice(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $token = $this->entityManager->getRepository(RefreshToken::class)->find($id);

        if (!$token || $token->getUser()->getId() !== $user->getId()) {
            return $this->json(['message' => 'Session introuvable'], 404);
        }

        $this->entityManager->remove($token);
        $this->entityManager->flush();

        return $this->json(['message' => 'Appareil déconnecté avec succès']);
    }

    private function parseUserAgent(string $ua): array
    {
        $os = 'Inconnu';
        $browser = '';

        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = stripos($ua, 'iPad') !== false ? 'iOS, Apple iPad' : 'iOS, Apple iPhone';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }

        // Expo Go / React Native
        if (stripos($ua, 'Expo') !== false || stripos($ua, 'okhttp') !== false) {
            $browser = 'Expo Go';
        } elseif (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('/Safari\/[\d.]+/', $ua) && stripos($ua, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (preg_match('/Edg\/(\d+)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1];
        }

        $label = $browser ? "$os - $browser" : $os;

        return [
            'os'      => $os,
            'browser' => $browser,
            'label'   => $label,
            'isMobile' => (bool) preg_match('/iPhone|iPad|Android|Mobile/i', $ua),
        ];
    }

    private function isSameDevice(string $storedUA, string $currentUA): bool
    {
        if (!$storedUA || !$currentUA) return false;
        // Comparer les parties significatives (OS + navigateur principal)
        return $storedUA === $currentUA;
    }
}
