<?php

namespace App\Controller\Api;

use App\Entity\Room;
use App\Entity\Listing;
use App\Repository\RoomRepository;
use App\Repository\ListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/rooms')]
class RoomController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomRepository $roomRepository,
        private ListingRepository $listingRepository,
        private ValidatorInterface $validator
    ) {}

    /**
     * Liste des chambres d'un listing (public)
     */
    #[Route('/listing/{listingId}', name: 'api_rooms_by_listing', methods: ['GET'])]
    public function getRoomsByListing(int $listingId): JsonResponse
    {
        $listing = $this->listingRepository->find($listingId);
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        $rooms = $this->roomRepository->findByListing($listing);
        
        // Grouper par type
        $grouped = [];
        foreach ($rooms as $room) {
            $type = $room->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [
                    'type' => $type,
                    'typeLabel' => $room->getTypeLabel(),
                    'count' => 0,
                    'rooms' => []
                ];
            }
            $grouped[$type]['count']++;
            $grouped[$type]['rooms'][] = $room->toArray();
        }

        return $this->json([
            'data' => array_values($grouped),
            'total' => count($rooms),
            'types' => array_keys($grouped)
        ]);
    }

    /**
     * Chambres disponibles pour une période
     */
    #[Route('/listing/{listingId}/available', name: 'api_rooms_available', methods: ['GET'])]
    public function getAvailableRooms(int $listingId, Request $request): JsonResponse
    {
        $listing = $this->listingRepository->find($listingId);
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');

        if (!$startDate || !$endDate) {
            return $this->json(['error' => 'Les dates sont requises'], 400);
        }

        try {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Format de date invalide'], 400);
        }

        $availableRooms = $this->roomRepository->findAvailableRooms($listing, $start, $end);
        
        // Grouper par type
        $grouped = [];
        foreach ($availableRooms as $room) {
            $type = $room->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [
                    'type' => $type,
                    'typeLabel' => $room->getTypeLabel(),
                    'count' => 0,
                    'rooms' => []
                ];
            }
            $grouped[$type]['count']++;
            $grouped[$type]['rooms'][] = $room->toArray();
        }

        return $this->json([
            'data' => array_values($grouped),
            'total' => count($availableRooms),
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }

    /**
     * Détail d'une chambre avec calendrier
     */
    #[Route('/{id}', name: 'api_room_detail', methods: ['GET'])]
    public function getRoomDetail(int $id, Request $request): JsonResponse
    {
        $room = $this->roomRepository->find($id);
        if (!$room) {
            return $this->json(['error' => 'Chambre non trouvée'], 404);
        }

        $data = $room->toArray();

        // Ajouter le calendrier si demandé
        if ($request->query->get('with_calendar')) {
            $startDate = $request->query->get('start_date', date('Y-m-d'));
            $endDate = $request->query->get('end_date', date('Y-m-d', strtotime('+60 days')));
            
            try {
                $start = new \DateTime($startDate);
                $end = new \DateTime($endDate);
                $data['calendar'] = $this->roomRepository->getRoomCalendar($room, $start, $end);
            } catch (\Exception $e) {
                $data['calendar'] = [];
            }
        }

        return $this->json(['data' => $data]);
    }

    /**
     * Créer une chambre (propriétaire uniquement)
     */
    #[Route('/listing/{listingId}', name: 'api_room_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createRoom(int $listingId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $listing = $this->listingRepository->find($listingId);
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], 404);
        }

        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);

        $room = new Room();
        $room->setListing($listing);
        $room->setNumber($data['number'] ?? '');
        $room->setType($data['type'] ?? Room::TYPE_SIMPLE);
        $room->setName($data['name'] ?? null);
        $room->setDescription($data['description'] ?? null);
        $room->setPricePerNight($data['price_per_night'] ?? $listing->getPrice());
        $room->setCapacity($data['capacity'] ?? 2);
        $room->setBeds($data['beds'] ?? 1);
        $room->setAmenities($data['amenities'] ?? []);
        $room->setImages($data['images'] ?? []);

        $errors = $this->validator->validate($room);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $this->entityManager->persist($room);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Chambre créée avec succès',
            'data' => $room->toArray()
        ], 201);
    }

    /**
     * Modifier une chambre
     */
    #[Route('/{id}', name: 'api_room_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateRoom(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $room = $this->roomRepository->find($id);
        if (!$room) {
            return $this->json(['error' => 'Chambre non trouvée'], 404);
        }

        if ($room->getListing()->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['number'])) $room->setNumber($data['number']);
        if (isset($data['type'])) $room->setType($data['type']);
        if (isset($data['name'])) $room->setName($data['name']);
        if (isset($data['description'])) $room->setDescription($data['description']);
        if (isset($data['price_per_night'])) $room->setPricePerNight($data['price_per_night']);
        if (isset($data['capacity'])) $room->setCapacity($data['capacity']);
        if (isset($data['beds'])) $room->setBeds($data['beds']);
        if (isset($data['amenities'])) $room->setAmenities($data['amenities']);
        if (isset($data['images'])) $room->setImages($data['images']);
        if (isset($data['status'])) $room->setStatus($data['status']);

        $room->setUpdatedAt(new \DateTime());

        $errors = $this->validator->validate($room);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Chambre modifiée avec succès',
            'data' => $room->toArray()
        ]);
    }

    /**
     * Supprimer une chambre
     */
    #[Route('/{id}', name: 'api_room_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteRoom(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        $room = $this->roomRepository->find($id);
        if (!$room) {
            return $this->json(['error' => 'Chambre non trouvée'], 404);
        }

        if ($room->getListing()->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        // Vérifier s'il y a des réservations actives
        $activeBookings = $room->getBookings()->filter(function($booking) {
            return in_array($booking->getStatus(), ['pending', 'accepted', 'confirmed', 'active']);
        });

        if ($activeBookings->count() > 0) {
            return $this->json([
                'error' => 'Impossible de supprimer une chambre avec des réservations actives'
            ], 400);
        }

        $this->entityManager->remove($room);
        $this->entityManager->flush();

        return $this->json(['message' => 'Chambre supprimée avec succès']);
    }

    /**
     * Vérifier disponibilité d'une chambre
     */
    #[Route('/{id}/check-availability', name: 'api_room_check_availability', methods: ['POST'])]
    public function checkRoomAvailability(int $id, Request $request): JsonResponse
    {
        $room = $this->roomRepository->find($id);
        if (!$room) {
            return $this->json(['error' => 'Chambre non trouvée'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$startDate || !$endDate) {
            return $this->json(['error' => 'Les dates sont requises'], 400);
        }

        try {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Format de date invalide'], 400);
        }

        $isAvailable = $this->roomRepository->isRoomAvailable($room, $start, $end);
        
        // Calculer les montants
        $nights = $start->diff($end)->days;
        $pricePerNight = (float)$room->getPricePerNight();
        $totalAmount = $pricePerNight * $nights;
        $depositAmount = $pricePerNight * 1; // 1 nuit de caution

        return $this->json([
            'available' => $isAvailable,
            'room' => $room->toArray(),
            'amounts' => [
                'price_per_night' => $pricePerNight,
                'nights' => $nights,
                'total_amount' => $totalAmount,
                'deposit_amount' => $depositAmount
            ]
        ]);
    }
}
