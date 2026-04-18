<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-vapid-keys',
    description: 'Génère les clés VAPID pour Web Push API'
)]
class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Génération des clés VAPID');

        try {
            // Utiliser web-push si disponible via npm
            $command = 'npx web-push generate-vapid-keys 2>&1';
            $output_text = shell_exec($command);

            if ($output_text && strpos($output_text, 'Public Key') !== false) {
                // Parser la sortie
                preg_match('/Public Key:\s*(.+)/', $output_text, $publicMatch);
                preg_match('/Private Key:\s*(.+)/', $output_text, $privateMatch);

                if ($publicMatch && $privateMatch) {
                    $publicKey = trim($publicMatch[1]);
                    $privateKey = trim($privateMatch[1]);

                    $io->success('Clés VAPID générées avec succès !');
                    $io->section('Clés générées:');
                    $io->text([
                        'VAPID_PUBLIC_KEY=' . $publicKey,
                        'VAPID_PRIVATE_KEY=' . $privateKey,
                        'VAPID_SUBJECT=mailto:admin@planb.com'
                    ]);

                    $io->note('Copiez ces lignes dans votre fichier .env');

                    return Command::SUCCESS;
                }
            }

            // Alternative: générer manuellement avec OpenSSL
            $io->warning('web-push non disponible, génération avec OpenSSL...');

            $privateKey = openssl_pkey_new([
                'curve_name' => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ]);

            if (!$privateKey) {
                throw new \Exception('Erreur lors de la génération des clés');
            }

            $privateKeyPem = '';
            openssl_pkey_export($privateKey, $privateKeyPem);

            // Extraire la clé publique
            $publicKeyDetails = openssl_pkey_get_details($privateKey);
            $publicKeyPem = $publicKeyDetails['key'];

            $io->note('Génération manuelle non complète. Utilisez:');
            $io->text([
                'npm install -g web-push',
                'web-push generate-vapid-keys'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur: ' . $e->getMessage());
            $io->note([
                'Pour générer les clés manuellement:',
                '1. Installer: npm install -g web-push',
                '2. Exécuter: web-push generate-vapid-keys',
                '3. Copier les clés dans .env'
            ]);

            return Command::FAILURE;
        }
    }
}


