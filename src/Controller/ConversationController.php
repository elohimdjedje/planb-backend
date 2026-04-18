<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\ListingRepository;
use App\Repository\MessageRepository;
use App\Service\SocketIoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/conversations')]
class ConversationController extends AbstractController
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private ListingRepository $listingRepository,
        private EntityManagerInterface $entityManager,
        private SocketIoService $socketIoService
    ) {
    }

    #[Route('', name: 'conversations_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $conversations = $this->conversationRepository->findByUser($user);

        $data = array_map(function ($conversation) use ($user) {
            $listing = $conversation->getListing();
            $otherUser = $conversation->getOtherUser($user);
            $lastMessage = $conversation->getLastMessage();
            $unreadCount = $conversation->countUnreadFor($user);
            
            $images = $listing->getImages()->toArray();

            return [
                'id' => $conversation->getId(),
                'listing' => [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle(),
                    'price' => (float) $listing->getPrice(),
                    'currency' => $listing->getCurrency(),
                    'mainImage' => !empty($images) ? $images[0]->getUrl() : null,
                    'status' => $listing->getStatus()
                ],
                'otherUser' => [
                    'id' => $otherUser->getId(),
                    'fullName' => $otherUser->getFullName(),
                    'profilePicture' => $otherUser->getProfilePicture(),
                    'isPro' => $otherUser->isPro()
                ],
                'lastMessage' => $lastMessage ? [
                    'content' => $lastMessage->getContent(),
                    'createdAt' => $lastMessage->getCreatedAt()?->format('c'),
                    'isFromMe' => $lastMessage->getSender()->getId() === $user->getId()
                ] : null,
                'unreadCount' => $unreadCount,
                'lastMessageAt' => $conversation->getLastMessageAt()?->format('c')
            ];
        }, $conversations);

        usort($data, function ($a, $b) {
            return strtotime($b['lastMessageAt']) - strtotime($a['lastMessageAt']);
        });

        $totalUnread = $this->conversationRepository->countUnreadConversations($user);

        return $this->json([
            'conversations' => $data,
            'total' => count($data),
            'totalUnread' => $totalUnread
        ]);
    }

    #[Route('/{id}', name: 'conversations_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $conversation = $this->conversationRepository->find($id);
        
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($conversation->getBuyer()->getId() !== $user->getId() 
            && $conversation->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $this->messageRepository->markAllAsRead($conversation, $user);

        $messages = $this->messageRepository->findByConversation($conversation);
        $listing = $conversation->getListing();
        $otherUser = $conversation->getOtherUser($user);

        $messagesData = array_map(function ($message) use ($user) {
            return [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'isFromMe' => $message->getSender()->getId() === $user->getId(),
                'isRead' => $message->isRead(),
                'createdAt' => $message->getCreatedAt()?->format('c')
            ];
        }, $messages);

        return $this->json([
            'id' => $conversation->getId(),
            'listing' => [
                'id' => $listing->getId(),
                'title' => $listing->getTitle(),
                'price' => (float) $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'status' => $listing->getStatus()
            ],
            'otherUser' => [
                'id' => $otherUser->getId(),
                'fullName' => $otherUser->getFullName(),
                'profilePicture' => $otherUser->getProfilePicture(),
                'phone' => $otherUser->getPhone(),
                'isPro' => $otherUser->isPro()
            ],
            'messages' => $messagesData,
            'createdAt' => $conversation->getCreatedAt()?->format('c')
        ]);
    }

    #[Route('/start/{listingId}', name: 'conversations_start', methods: ['POST'])]
    public function start(int $listingId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        // NOUVEAU: Permettre les discussions sans compte
        // Si pas d'utilisateur connecté, retourner les infos pour contact direct
        if (!$user instanceof User) {
            $listing = $this->listingRepository->find($listingId);
            
            if (!$listing) {
                return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
            }

            $seller = $listing->getUser();
            
            // ✅ SECURITY: Ne pas exposer les PII du vendeur aux anonymes
            // Masquer partiellement le téléphone et email
            $phone = $seller->getPhone();
            $maskedPhone = $phone ? substr($phone, 0, 4) . '****' . substr($phone, -2) : null;
            $email = $seller->getEmail();
            $maskedEmail = $email ? substr($email, 0, 2) . '***@' . substr(strrchr($email, '@'), 1) : null;
            
            return $this->json([
                'requiresAuth' => false,
                'message' => 'Connectez-vous pour contacter le vendeur directement',
                'seller' => [
                    'id' => $seller->getId(),
                    'firstName' => $seller->getFirstName(),
                    'phone' => $maskedPhone,
                    'whatsappPhone' => $maskedPhone,
                    'email' => $maskedEmail
                ]
            ], Response::HTTP_OK);
        }

        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($listing->getUser()->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas contacter votre propre annonce'], Response::HTTP_BAD_REQUEST);
        }

        $conversation = $this->conversationRepository->findOrCreate($listing, $user);

        // Envoyer le message initial si fourni
        $data = json_decode($request->getContent(), true);
        $messageContent = $data['message'] ?? null;
        $firstMessage = null;

        if ($messageContent && trim($messageContent) !== '') {
            $message = new Message();
            $message->setConversation($conversation);
            $message->setSender($user);
            $message->setContent(trim($messageContent));

            $this->entityManager->persist($message);
            $conversation->setLastMessageAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $firstMessage = [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'isFromMe' => true,
                'isRead' => false,
                'createdAt' => $message->getCreatedAt()?->format('c')
            ];

            // Émettre via Socket.io
            try {
                $this->socketIoService->emitNewMessage($conversation->getId(), [
                    'id' => $message->getId(),
                    'content' => $message->getContent(),
                    'senderId' => $user->getId(),
                    'sender' => [
                        'id' => $user->getId(),
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                    ],
                    'conversationId' => $conversation->getId(),
                    'isRead' => false,
                    'createdAt' => $message->getCreatedAt()?->format('c')
                ]);
            } catch (\Exception $e) {
                // Logger l'erreur mais ne pas bloquer la réponse
            }
        }

        return $this->json([
            'requiresAuth' => true,
            'message' => 'Conversation créée',
            'conversationId' => $conversation->getId(),
            'firstMessage' => $firstMessage
        ], Response::HTTP_OK);
    }
}
