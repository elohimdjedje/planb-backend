<?php

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Handler personnalisé pour retourner les données utilisateur avec le token JWT
 */
class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        /** @var User $user */
        $user = $token->getUser();
        
        // Générer le token JWT
        $jwt = $this->jwtManager->create($user);
        
        // Retourner le token ET les données utilisateur
        return new JsonResponse([
            'token' => $jwt,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'phone' => $user->getPhone(),
                'whatsappPhone' => $user->getWhatsappPhone(),
                'accountType' => $user->getAccountType(),
                'isPro' => $user->isPro(),
                'isLifetimePro' => $user->isLifetimePro(),
                'profilePicture' => $user->getProfilePicture(),
                'country' => $user->getCountry(),
                'city' => $user->getCity(),
                'roles' => $user->getRoles(),
                'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles()),
                'isVerified' => $user->isIdentityVerified(),
                'canPublish' => $user->canPublish(),
                'verificationBadges' => $user->getVerificationBadges() ?? [],
                'verificationCategory' => $user->getVerificationCategory(),
                'verificationStatus' => $user->getVerificationStatus(),
            ]
        ], Response::HTTP_OK);
    }
}
