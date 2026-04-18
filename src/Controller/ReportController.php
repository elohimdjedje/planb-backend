<?php

namespace App\Controller;

use App\Entity\Report;
use App\Entity\User;
use App\Repository\ListingRepository;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/reports')]
class ReportController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReportRepository $reportRepository,
        private ListingRepository $listingRepository,
        private ValidatorInterface $validator
    ) {
    }

    #[Route('', name: 'reports_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['listingId']) || !isset($data['reason'])) {
            return $this->json([
                'error' => 'Données manquantes',
                'required' => ['listingId', 'reason']
            ], Response::HTTP_BAD_REQUEST);
        }

        $listing = $this->listingRepository->find($data['listingId']);
        
        if (!$listing) {
            return $this->json(['error' => 'Annonce introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($user instanceof User) {
            $hasReported = $this->reportRepository->hasUserReportedListing($user, $listing);
            if ($hasReported) {
                return $this->json([
                    'error' => 'Vous avez déjà signalé cette annonce'
                ], Response::HTTP_CONFLICT);
            }
        }

        $report = new Report();
        if ($user instanceof User) {
            $report->setReporter($user);
        }
        $report->setListing($listing);
        $report->setReason($data['reason']);
        
        if (isset($data['description'])) {
            $report->setDescription($data['description']);
        }

        $errors = $this->validator->validate($report);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json([
                'error' => 'Validation échouée',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'Signalement enregistré. Notre équipe va examiner cette annonce.',
            'reportId' => $report->getId()
        ], Response::HTTP_CREATED);
    }

    #[Route('/my', name: 'reports_my', methods: ['GET'])]
    public function myReports(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $reports = $this->reportRepository->findBy(
            ['reporter' => $user],
            ['createdAt' => 'DESC']
        );

        $data = array_map(function (Report $report) {
            $listing = $report->getListing();
            return [
                'id' => $report->getId(),
                'reason' => $report->getReason(),
                'reasonLabel' => $report->getReasonLabel(),
                'description' => $report->getDescription(),
                'status' => $report->getStatus(),
                'listing' => [
                    'id' => $listing->getId(),
                    'title' => $listing->getTitle()
                ],
                'createdAt' => $report->getCreatedAt()?->format('c')
            ];
        }, $reports);

        return $this->json([
            'reports' => $data,
            'total' => count($data)
        ]);
    }
}
