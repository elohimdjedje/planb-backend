<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\VisitSlot;
use App\Repository\ListingRepository;
use App\Repository\VisitSlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/visit-slots')]
class VisitSlotController extends AbstractController
{
    public function __construct(
        private VisitSlotRepository $visitSlotRepository,
        private ListingRepository $listingRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Récupérer les créneaux disponibles pour une annonce (public)
     */
    #[Route('/listing/{listingId}', name: 'app_visit_slots_by_listing', methods: ['GET'])]
    public function getByListing(int $listingId): JsonResponse
    {
        $this->visitSlotRepository->expirePastSlots();

        $listing = $this->listingRepository->find($listingId);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $slots = $this->visitSlotRepository->findAvailableForListing($listing);

        return $this->json([
            'data' => array_map(fn($slot) => $slot->toArray(), $slots),
            'total' => count($slots),
        ]);
    }

    /**
     * Récupérer les créneaux du propriétaire connecté
     */
    #[Route('/my-slots', name: 'app_visit_slots_my', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getMySlots(Request $request): JsonResponse
    {
        $this->visitSlotRepository->expirePastSlots();

        /** @var User $user */
        $user = $this->getUser();
        $status = $request->query->get('status');

        $slots = $this->visitSlotRepository->findByOwner($user, $status);
        $stats = $this->visitSlotRepository->countByStatusForOwner($user);

        return $this->json([
            'data' => array_map(fn($slot) => $slot->toArray(), $slots),
            'stats' => $stats,
        ]);
    }

    /**
     * Récupérer les visites réservées par l'utilisateur
     */
    #[Route('/my-bookings', name: 'app_visit_slots_my_bookings', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getMyBookings(): JsonResponse
    {
        $this->visitSlotRepository->expirePastSlots();

        /** @var User $user */
        $user = $this->getUser();

        $slots = $this->visitSlotRepository->findBookedByUser($user);

        return $this->json([
            'data' => array_map(fn($slot) => $slot->toArray(), $slots),
        ]);
    }

    /**
     * Créer un nouveau créneau de disponibilité
     */
    #[Route('', name: 'app_visit_slots_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        // Validation
        if (empty($data['listingId']) || empty($data['date']) || empty($data['startTime']) || empty($data['endTime'])) {
            return $this->json(['error' => 'Données manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->listingRepository->find($data['listingId']);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que l'utilisateur est le propriétaire de l'annonce
        if ($listing->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas le propriétaire de cette annonce'], Response::HTTP_FORBIDDEN);
        }

        $date = new \DateTime($data['date']);
        $startTime = new \DateTime($data['startTime']);
        $endTime = new \DateTime($data['endTime']);

        // Vérifier que la date est dans le futur
        if ($date < new \DateTime('today')) {
            return $this->json(['error' => 'La date doit être dans le futur'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier que l'heure de fin est après l'heure de début
        if ($endTime <= $startTime) {
            return $this->json(['error' => 'L\'heure de fin doit être après l\'heure de début'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier si le créneau existe déjà
        if ($this->visitSlotRepository->slotExists($listing, $date, $startTime, $endTime)) {
            return $this->json(['error' => 'Ce créneau existe déjà'], Response::HTTP_CONFLICT);
        }

        $slot = new VisitSlot();
        $slot->setListing($listing);
        $slot->setOwner($user);
        $slot->setDate($date);
        $slot->setStartTime($startTime);
        $slot->setEndTime($endTime);
        $slot->setNotes($data['notes'] ?? null);

        // Gestion des créneaux récurrents
        if (!empty($data['isRecurring']) && !empty($data['recurringPattern'])) {
            $slot->setIsRecurring(true);
            $slot->setRecurringPattern($data['recurringPattern']);
        }

        $this->entityManager->persist($slot);

        // Générer les créneaux récurrents si demandé
        $recurringSlots = [];
        if ($slot->isRecurring()) {
            $recurringSlots = $this->visitSlotRepository->generateRecurringSlots($slot, $data['recurringWeeks'] ?? 4);
            foreach ($recurringSlots as $recurringSlot) {
                $this->entityManager->persist($recurringSlot);
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Créneau créé avec succès',
            'data' => $slot->toArray(),
            'recurringCreated' => count($recurringSlots),
        ], Response::HTTP_CREATED);
    }

    /**
     * Modifier un créneau
     */
    #[Route('/{id}', name: 'app_visit_slots_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $slot = $this->visitSlotRepository->find($id);

        if (!$slot) {
            return $this->json(['error' => 'Créneau non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if ($slot->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($slot->getStatus() === VisitSlot::STATUS_BOOKED) {
            return $this->json(['error' => 'Impossible de modifier un créneau réservé'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['date'])) {
            $slot->setDate(new \DateTime($data['date']));
        }
        if (isset($data['startTime'])) {
            $slot->setStartTime(new \DateTime($data['startTime']));
        }
        if (isset($data['endTime'])) {
            $slot->setEndTime(new \DateTime($data['endTime']));
        }
        if (isset($data['notes'])) {
            $slot->setNotes($data['notes']);
        }

        $slot->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Créneau mis à jour',
            'data' => $slot->toArray(),
        ]);
    }

    /**
     * Supprimer un créneau
     */
    #[Route('/{id}', name: 'app_visit_slots_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $slot = $this->visitSlotRepository->find($id);

        if (!$slot) {
            return $this->json(['error' => 'Créneau non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if ($slot->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($slot->getStatus() === VisitSlot::STATUS_BOOKED) {
            // Annuler plutôt que supprimer si déjà réservé
            $slot->cancel();
            $this->entityManager->flush();
            return $this->json(['message' => 'Créneau annulé (était réservé)']);
        }

        $this->entityManager->remove($slot);
        $this->entityManager->flush();

        return $this->json(['message' => 'Créneau supprimé']);
    }

    /**
     * Réserver un créneau (côté client)
     */
    #[Route('/{id}/book', name: 'app_visit_slots_book', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function book(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $slot = $this->visitSlotRepository->find($id);

        if (!$slot) {
            return $this->json(['error' => 'Créneau non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que l'utilisateur n'est pas le propriétaire
        if ($slot->getOwner()->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas réserver votre propre créneau'], Response::HTTP_FORBIDDEN);
        }

        // Vérifier que le créneau est disponible
        if (!$slot->isAvailable()) {
            return $this->json(['error' => 'Ce créneau n\'est plus disponible'], Response::HTTP_CONFLICT);
        }

        // Réserver le créneau
        $slot->book(
            $user,
            $data['message'] ?? null,
            $data['phone'] ?? $user->getPhone()
        );

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Visite réservée avec succès',
            'data' => $slot->toArray(),
        ]);
    }

    /**
     * Annuler une réservation (côté client ou propriétaire)
     */
    #[Route('/{id}/cancel', name: 'app_visit_slots_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $slot = $this->visitSlotRepository->find($id);

        if (!$slot) {
            return $this->json(['error' => 'Créneau non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // Autoriser l'annulation par le propriétaire ou le client qui a réservé
        $isOwner = $slot->getOwner()->getId() === $user->getId();
        $isBooker = $slot->getBookedBy() && $slot->getBookedBy()->getId() === $user->getId();

        if (!$isOwner && !$isBooker) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $slot->cancel();
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Réservation annulée',
            'data' => $slot->toArray(),
        ]);
    }

    /**
     * Marquer une visite comme complétée
     */
    #[Route('/{id}/complete', name: 'app_visit_slots_complete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function complete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $slot = $this->visitSlotRepository->find($id);

        if (!$slot) {
            return $this->json(['error' => 'Créneau non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if ($slot->getOwner()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($slot->getStatus() !== VisitSlot::STATUS_BOOKED) {
            return $this->json(['error' => 'Ce créneau n\'est pas réservé'], Response::HTTP_BAD_REQUEST);
        }

        $slot->complete();
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Visite marquée comme complétée',
            'data' => $slot->toArray(),
        ]);
    }
}
