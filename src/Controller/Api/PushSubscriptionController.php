<?php

namespace App\Controller\Api;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/push-subscriptions')]
#[IsGranted('ROLE_USER')]
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PushSubscriptionRepository $pushSubscriptionRepository
    ) {
    }

    /**
     * Enregistrer une souscription push (Web ou Mobile)
     * 
     * POST /api/v1/push-subscriptions
     */
    #[Route('', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        // Pour Web Push API
        if (isset($data['endpoint'])) {
            $endpoint = $data['endpoint'];
            $p256dh = $data['keys']['p256dh'] ?? null;
            $auth = $data['keys']['auth'] ?? null;
            $platform = $data['platform'] ?? 'web';

            // Vérifier si la souscription existe déjà
            $existing = $this->pushSubscriptionRepository->findByEndpoint($endpoint);
            
            if ($existing) {
                // Mettre à jour si c'est le même utilisateur
                if ($existing->getUser()->getId() === $user->getId()) {
                    $existing->setP256dh($p256dh);
                    $existing->setAuth($auth);
                    $existing->setIsActive(true);
                    $existing->setLastUsedAt(new \DateTime());
                    $this->entityManager->flush();

                    return $this->json([
                        'success' => true,
                        'message' => 'Souscription mise à jour',
                        'id' => $existing->getId()
                    ]);
                } else {
                    return $this->json([
                        'success' => false,
                        'error' => 'Cette souscription appartient à un autre utilisateur'
                    ], Response::HTTP_CONFLICT);
                }
            }

            // Créer une nouvelle souscription
            $subscription = new PushSubscription();
            $subscription->setUser($user);
            $subscription->setEndpoint($endpoint);
            $subscription->setP256dh($p256dh);
            $subscription->setAuth($auth);
            $subscription->setPlatform($platform);
            $subscription->setMetadata([
                'userAgent' => $request->headers->get('User-Agent'),
                'ip' => $request->getClientIp()
            ]);

            $this->entityManager->persist($subscription);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Souscription enregistrée',
                'id' => $subscription->getId()
            ], Response::HTTP_CREATED);

        // Pour Mobile (FCM/APNS)
        } elseif (isset($data['deviceToken'])) {
            $deviceToken = $data['deviceToken'];
            $platform = $data['platform'] ?? 'android'; // 'ios' ou 'android'

            // Chercher une souscription existante avec ce token
            $existing = $this->pushSubscriptionRepository->createQueryBuilder('ps')
                ->where('ps.deviceToken = :token')
                ->andWhere('ps.platform = :platform')
                ->setParameter('token', $deviceToken)
                ->setParameter('platform', $platform)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing) {
                if ($existing->getUser()->getId() === $user->getId()) {
                    $existing->setIsActive(true);
                    $existing->setLastUsedAt(new \DateTime());
                    $this->entityManager->flush();

                    return $this->json([
                        'success' => true,
                        'message' => 'Souscription mise à jour',
                        'id' => $existing->getId()
                    ]);
                } else {
                    // Réassigner à l'utilisateur actuel
                    $existing->setUser($user);
                    $existing->setIsActive(true);
                    $existing->setLastUsedAt(new \DateTime());
                    $this->entityManager->flush();

                    return $this->json([
                        'success' => true,
                        'message' => 'Souscription réassignée',
                        'id' => $existing->getId()
                    ]);
                }
            }

            // Créer une nouvelle souscription mobile
            $subscription = new PushSubscription();
            $subscription->setUser($user);
            $subscription->setDeviceToken($deviceToken);
            $subscription->setPlatform($platform);
            $subscription->setMetadata([
                'userAgent' => $request->headers->get('User-Agent'),
                'ip' => $request->getClientIp(),
                'appVersion' => $data['appVersion'] ?? null
            ]);

            $this->entityManager->persist($subscription);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Souscription mobile enregistrée',
                'id' => $subscription->getId()
            ], Response::HTTP_CREATED);
        }

        return $this->json([
            'success' => false,
            'error' => 'Données de souscription manquantes'
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Désactiver une souscription push
     * 
     * DELETE /api/v1/push-subscriptions/{id}
     */
    #[Route('/{id}', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscription = $this->pushSubscriptionRepository->find($id);

        if (!$subscription || $subscription->getUser()->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Souscription introuvable'
            ], Response::HTTP_NOT_FOUND);
        }

        $subscription->setIsActive(false);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Souscription désactivée'
        ]);
    }

    /**
     * Désactiver toutes les souscriptions de l'utilisateur
     * 
     * DELETE /api/v1/push-subscriptions
     */
    #[Route('', name: 'api_push_unsubscribe_all', methods: ['DELETE'])]
    public function unsubscribeAll(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscriptions = $this->pushSubscriptionRepository->findActiveByUser($user);

        foreach ($subscriptions as $subscription) {
            $subscription->setIsActive(false);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => count($subscriptions) . ' souscriptions désactivées',
            'count' => count($subscriptions)
        ]);
    }

    /**
     * Lister les souscriptions de l'utilisateur
     * 
     * GET /api/v1/push-subscriptions
     */
    #[Route('', name: 'api_push_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $subscriptions = $this->pushSubscriptionRepository->findActiveByUser($user);

        $data = array_map(function($subscription) {
            return [
                'id' => $subscription->getId(),
                'platform' => $subscription->getPlatform(),
                'createdAt' => $subscription->getCreatedAt()->format('c'),
                'lastUsedAt' => $subscription->getLastUsedAt()?->format('c'),
                'metadata' => $subscription->getMetadata()
            ];
        }, $subscriptions);

        return $this->json([
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ]);
    }
}


