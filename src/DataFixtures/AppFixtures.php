<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Listing;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Creer un utilisateur de test
        $user = new User();
        $user->setEmail('test@planb.ci');
        $user->setFirstName('Jean');
        $user->setLastName('Dupont');
        $user->setPhone('+2250700000000');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $manager->persist($user);

        // Annonces immobilieres
        $listings = [
            [
                'title' => 'Appartement 3 pieces Cocody Riviera',
                'description' => 'Bel appartement de 3 pieces entierement renove, cuisine equipee, salon spacieux, 2 chambres, 1 salle de bain. Quartier calme et securise.',
                'price' => 150000,
                'category' => 'immobilier',
                'subCategory' => 'appartement',
                'transactionType' => 'location',
                'city' => 'Abidjan',
                'neighborhood' => 'Cocody Riviera',
            ],
            [
                'title' => 'Villa duplex 5 pieces Riviera Golf',
                'description' => 'Magnifique villa duplex avec piscine, jardin, 4 chambres, 3 salles de bain, garage 2 voitures. Ideal pour famille.',
                'price' => 450000,
                'category' => 'immobilier',
                'subCategory' => 'villa',
                'transactionType' => 'location',
                'city' => 'Abidjan',
                'neighborhood' => 'Riviera Golf',
            ],
            [
                'title' => 'Terrain 500m2 Bingerville',
                'description' => 'Terrain bien situe, ACD disponible, acces facile, ideal pour construction de villa.',
                'price' => 25000000,
                'category' => 'immobilier',
                'subCategory' => 'terrain',
                'transactionType' => 'vente',
                'city' => 'Abidjan',
                'neighborhood' => 'Bingerville',
            ],
            [
                'title' => 'Studio meuble Plateau',
                'description' => 'Studio moderne entierement meuble, climatise, eau chaude, internet inclus. Proche commerces.',
                'price' => 80000,
                'category' => 'immobilier',
                'subCategory' => 'studio',
                'transactionType' => 'location',
                'city' => 'Abidjan',
                'neighborhood' => 'Plateau',
            ],
            [
                'title' => 'Maison 4 pieces Yopougon',
                'description' => 'Maison familiale avec cour, 3 chambres, salon, cuisine, toilettes externes. Quartier anime.',
                'price' => 75000,
                'category' => 'immobilier',
                'subCategory' => 'maison',
                'transactionType' => 'location',
                'city' => 'Abidjan',
                'neighborhood' => 'Yopougon',
            ],
        ];

        foreach ($listings as $data) {
            $listing = new Listing();
            $listing->setTitle($data['title']);
            $listing->setDescription($data['description']);
            $listing->setPrice($data['price']);
            $listing->setCategory($data['category']);
            $listing->setSubCategory($data['subCategory']);
            $listing->setTransactionType($data['transactionType']);
            $listing->setCity($data['city']);
            $listing->setNeighborhood($data['neighborhood']);
            $listing->setOwner($user);
            $listing->setStatus('active');
            $listing->setPriceUnit('mois');
            $manager->persist($listing);
        }

        $manager->flush();
    }
}