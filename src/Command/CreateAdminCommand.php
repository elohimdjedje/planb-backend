<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Créer ou promouvoir un administrateur (console uniquement)'
)]
class CreateAdminCommand extends Command
{
    // Politique de mot de passe admin — niveau entreprise
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de l\'administrateur')
            ->addArgument('phone', InputArgument::OPTIONAL, 'Numéro de téléphone (+22507123456)')
            ->setHelp(
                "Crée un compte admin ou promeut un utilisateur existant.\n"
                . "Le mot de passe est saisi de façon sécurisée (non visible).\n"
                . "Utilisable UNIQUEMENT depuis le terminal serveur — jamais via l'API."
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim(strtolower($input->getArgument('email')));
        $phone = $input->getArgument('phone') ?? '+22507000000';

        // Validation email basique
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("Email invalide : $email");
            return Command::FAILURE;
        }

        // Saisie sécurisée du mot de passe (masqué)
        $password = $io->askHidden('Mot de passe admin (min 12 chars, majuscule, chiffre, symbole)');
        if (!$password) {
            $io->error('Mot de passe requis.');
            return Command::FAILURE;
        }

        $passwordError = $this->validatePasswordStrength($password);
        if ($passwordError) {
            $io->error($passwordError);
            return Command::FAILURE;
        }

        $confirm = $io->askHidden('Confirmez le mot de passe');
        if ($password !== $confirm) {
            $io->error('Les mots de passe ne correspondent pas.');
            return Command::FAILURE;
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);

        if ($existingUser) {
            // Promouvoir un utilisateur existant
            if (in_array('ROLE_ADMIN', $existingUser->getRoles(), true)) {
                $io->warning("$email est déjà administrateur.");
                if (!$io->confirm('Mettre à jour son mot de passe ?', false)) {
                    return Command::SUCCESS;
                }
            } else {
                $io->warning("Cet utilisateur existe. Il sera promu ROLE_ADMIN.");
                if (!$io->confirm('Confirmer la promotion ?', false)) {
                    $io->note('Opération annulée.');
                    return Command::SUCCESS;
                }
            }

            $existingUser->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            $hashedPassword = $this->passwordHasher->hashPassword($existingUser, $password);
            $existingUser->setPassword($hashedPassword);
            $this->entityManager->flush();

            $io->success("Utilisateur $email promu administrateur avec nouveau mot de passe.");
            return Command::SUCCESS;
        }

        // Créer un nouvel administrateur
        $admin = new User();
        $admin->setEmail($email);
        $admin->setPhone($phone);
        $admin->setFirstName('Admin');
        $admin->setLastName('System');
        $admin->setCountry('CI');
        $admin->setCity('Abidjan');
        // SÉCURITÉ : rôles assignés uniquement ici, jamais via l'API publique
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $admin->setAccountType('PRO');
        $admin->setIsLifetimePro(true);
        $admin->setIsEmailVerified(true);
        $admin->setIsPhoneVerified(true);

        $hashedPassword = $this->passwordHasher->hashPassword($admin, $password);
        $admin->setPassword($hashedPassword);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $io->success('Administrateur créé avec succès !');
        $io->table(
            ['Propriété', 'Valeur'],
            [
                ['Email', $email],
                ['Téléphone', $phone],
                ['Rôles', 'ROLE_USER, ROLE_ADMIN'],
                ['Compte', 'PRO (illimité)'],
                ['Créé via', 'Console (sécurisé)'],
            ]
        );
        $io->note('Endpoint de connexion : POST /api/v1/auth/login');

        return Command::SUCCESS;
    }

    /**
     * Politique OWASP : 12 chars min, majuscule, minuscule, chiffre, symbole.
     * Vérifié côté serveur — jamais côté client uniquement.
     */
    private function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Le mot de passe doit contenir au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une majuscule.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une minuscule.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Le mot de passe doit contenir au moins un chiffre.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Le mot de passe doit contenir au moins un caractère spécial (!@#$%^&*…).';
        }
        // Détection des mots de passe communs les plus évidents
        $forbidden = ['password', 'admin123', '123456789', 'planb', 'azerty'];
        foreach ($forbidden as $weak) {
            if (stripos($password, $weak) !== false) {
                return "Le mot de passe ne doit pas contenir '$weak'.";
            }
        }
        return null;
    }
}
