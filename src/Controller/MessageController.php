<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Service\SocketIoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/messages')]
class MessageController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private ValidatorInterface $validator,
        private SocketIoService $socketIoService
    ) {
    }

    #[Route('', name: 'messages_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['conversationId']) || !isset($data['content'])) {
            return $this->json(['error' => 'Données manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $conversation = $this->conversationRepository->find($data['conversationId']);
        
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($conversation->getBuyer()->getId() !== $user->getId() 
            && $conversation->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($user);
        $message->setContent(trim($data['content']));

        $errors = $this->validator->validate($message);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => 'Validation échouée', 'details' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($message);
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Préparer les données du message pour Socket.io
        $messageData = [
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
        ];

        // Émettre le message via Socket.io (en arrière-plan, non-bloquant)
        try {
            $this->socketIoService->emitNewMessage($conversation->getId(), $messageData);
        } catch (\Exception $e) {
            // Logger l'erreur mais ne pas bloquer la réponse
            // Le message est déjà sauvegardé en DB
        }

        return $this->json([
            'message' => 'Message envoyé',
            'data' => [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'isFromMe' => true,
                'isRead' => false,
                'createdAt' => $message->getCreatedAt()?->format('c')
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/read', name: 'messages_mark_read', methods: ['PUT'])]
    public function markAsRead(int $id): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $message = $this->messageRepository->find($id);
        
        if (!$message) {
            return $this->json(['error' => 'Message introuvable'], Response::HTTP_NOT_FOUND);
        }

        $conversation = $message->getConversation();

        if ($conversation->getBuyer()->getId() !== $user->getId() 
            && $conversation->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($message->getSender()->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas marquer vos propres messages comme lus'], Response::HTTP_BAD_REQUEST);
        }

        $message->markAsRead();
        $this->entityManager->flush();

        return $this->json(['message' => 'Message marqué comme lu']);
    }

    #[Route('/unread-count', name: 'messages_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $count = $this->messageRepository->countUnreadForUser($user);

        return $this->json(['unreadCount' => $count]);
    }
}
