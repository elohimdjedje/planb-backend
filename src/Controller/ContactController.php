<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/contact')]
class ContactController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ContactMessageRepository $contactMessageRepository
    ) {
    }

    #[Route('', name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Données invalides'], Response::HTTP_BAD_REQUEST);
        }

        $contactMessage = new ContactMessage();
        $contactMessage->setName($data['name'] ?? '');
        $contactMessage->setEmail($data['email'] ?? '');
        $contactMessage->setSubject($data['subject'] ?? '');
        $contactMessage->setMessage($data['message'] ?? '');

        $errors = $this->validator->validate($contactMessage);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($contactMessage);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Message envoyé avec succès',
            'id' => $contactMessage->getId()
        ], Response::HTTP_CREATED);
    }

    /**
     * Obtenir le lien WhatsApp pour contacter l'admin
     * GET /api/v1/contact/whatsapp
     */
    #[Route('/whatsapp', name: 'contact_whatsapp', methods: ['GET'])]
    public function getWhatsAppLink(Request $request): JsonResponse
    {
        $adminPhone = $_ENV['ADMIN_WHATSAPP_PHONE'] ?? '+2250000000000';
        $message = $request->query->get('message', 'Bonjour, j\'aimerais contacter l\'équipe Plan B');
        
        // Nettoyer le numéro de téléphone (supprimer les espaces, +, etc.)
        $phone = preg_replace('/[^0-9]/', '', $adminPhone);
        
        // Créer le lien WhatsApp
        $whatsappLink = 'https://wa.me/' . $phone . '?text=' . urlencode($message);
        
        return $this->json([
            'whatsappLink' => $whatsappLink,
            'phone' => $adminPhone,
            'message' => $message
        ]);
    }

    #[Route('/admin', name: 'contact_admin_list', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $status = $request->query->get('status', 'pending');
        $messages = $this->contactMessageRepository->findByStatus($status);

        return $this->json([
            'data' => array_map(fn($m) => $this->serializeMessage($m), $messages),
            'counts' => $this->contactMessageRepository->countByStatus()
        ]);
    }

    #[Route('/admin/{id}', name: 'contact_admin_show', methods: ['GET'])]
    public function adminShow(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $message = $this->contactMessageRepository->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeMessage($message));
    }

    #[Route('/admin/{id}/respond', name: 'contact_admin_respond', methods: ['POST'])]
    public function adminRespond(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $message = $this->contactMessageRepository->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['response']) || empty($data['response'])) {
            return $this->json(['error' => 'Réponse requise'], Response::HTTP_BAD_REQUEST);
        }

        $message->setResponse($data['response']);
        $message->setRespondedAt(new \DateTimeImmutable());
        $message->setRespondedBy($this->getUser());
        $message->setStatus('answered');

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Réponse envoyée',
            'data' => $this->serializeMessage($message)
        ]);
    }

    #[Route('/admin/{id}/close', name: 'contact_admin_close', methods: ['POST'])]
    public function adminClose(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $message = $this->contactMessageRepository->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $message->setStatus('closed');
        $this->entityManager->flush();

        return $this->json(['message' => 'Message fermé']);
    }

    private function serializeMessage(ContactMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'name' => $message->getName(),
            'email' => $message->getEmail(),
            'subject' => $message->getSubject(),
            'message' => $message->getMessage(),
            'status' => $message->getStatus(),
            'createdAt' => $message->getCreatedAt()->format('c'),
            'respondedAt' => $message->getRespondedAt()?->format('c'),
            'response' => $message->getResponse(),
        ];
    }
}
