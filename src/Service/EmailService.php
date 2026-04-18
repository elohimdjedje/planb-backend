<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Twig\Environment;

/**
 * Service d'envoi d'emails pour l'authentification et les notifications
 */
class EmailService
{
    private string $fromEmail;
    private string $fromName;
    private string $supportEmail;
    private string $supportEmailName;
    private string $appUrl;

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        string $fromEmail = 'noreply@planb.ci',
        string $fromName = 'Plan B',
        string $appUrl = 'http://localhost:5173'
    ) {
        $this->fromEmail = $_ENV['MAILER_FROM_EMAIL'] ?? $fromEmail;
        $this->fromName = $_ENV['MAILER_FROM_NAME'] ?? $fromName;
        $this->supportEmail = $_ENV['SUPPORT_EMAIL'] ?? 'aide.planb@gmail.com';
        $this->supportEmailName = $_ENV['SUPPORT_EMAIL_NAME'] ?? 'Plan B - Support';
        $this->appUrl = $_ENV['FRONTEND_URL'] ?? $appUrl;
    }

    /**
     * Vérifie si le service d'email est configuré
     */
    public function isConfigured(): bool
    {
        $mailerDsn = $_ENV['MAILER_DSN'] ?? null;
        return $mailerDsn && $mailerDsn !== 'null://null';
    }

    /**
     * Envoie un email de bienvenue après l'inscription
     */
    public function sendWelcomeEmail(User $user, string $verificationToken): bool
    {
        try {
            if (!$this->isConfigured()) {
                $this->logger->warning('MAILER_DSN not configured, cannot send welcome email', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
                return false;
            }

            $verificationUrl = $this->appUrl . '/verify-email?token=' . $verificationToken;

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Bienvenue sur Plan B ! 🎉')
                ->htmlTemplate('emails/welcome.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'verificationUrl' => $verificationUrl,
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('Welcome email sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);
            return false;
        }
    }

    /**
     * Envoie un email de vérification d'email
     */
    public function sendEmailVerification(User $user, string $verificationToken): bool
    {
        try {
            if (!$this->isConfigured()) {
                $this->logger->warning('MAILER_DSN not configured, cannot send verification email', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
                return false;
            }

            $verificationUrl = $this->appUrl . '/verify-email?token=' . $verificationToken;

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Vérifiez votre adresse email - Plan B')
                ->htmlTemplate('emails/verify-email.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'verificationUrl' => $verificationUrl,
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('Email verification sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email verification', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);
            return false;
        }
    }

    /**
     * Envoie un email de réinitialisation de mot de passe
     */
    public function sendPasswordResetEmail(User $user, string $resetCode, string $resetToken = ''): bool
    {
        try {
            // Vérifier si MAILER_DSN est configuré
            $mailerDsn = $_ENV['MAILER_DSN'] ?? null;
            if (!$mailerDsn || $mailerDsn === 'null://null') {
                $this->logger->warning('MAILER_DSN not configured, skipping email send', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail()
                ]);
                if ($_ENV['APP_ENV'] === 'dev') {
                    error_log("MAILER_DSN not configured. Email not sent.");
                    return false;
                }
                throw new \Exception('MAILER_DSN not configured');
            }

            // Lien sécurisé : token opaque uniquement (ni email ni code dans l'URL)
            $resetUrl = $resetToken 
                ? $this->appUrl . '/reset-password?token=' . $resetToken
                : $this->appUrl . '/reset-password';

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe - Plan B')
                ->htmlTemplate('emails/password-reset.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'resetCode' => $resetCode,
                    'resetUrl' => $resetUrl,
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('Password reset email sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send password reset email', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'trace' => $e->getTraceAsString()
            ]);
            // Ne pas faire échouer la requête si l'email échoue
            // Le code est toujours généré et peut être envoyé par SMS
            return false;
        }
    }

    /**
     * Envoie un email de confirmation de changement de mot de passe
     */
    public function sendPasswordChangedConfirmation(User $user): bool
    {
        try {
            if (!$this->isConfigured()) {
                $this->logger->warning('MAILER_DSN not configured, cannot send password changed confirmation', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
                return false;
            }

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Votre mot de passe a été modifié - Plan B')
                ->htmlTemplate('emails/password-changed.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('Password changed confirmation sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send password changed confirmation', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);
            return false;
        }
    }

    /**
     * Envoie un email contenant le code 2FA
     */
    public function send2FAEmail(User $user, string $code): bool
    {
        try {
            $mailerDsn = $_ENV['MAILER_DSN'] ?? null;
            if (!$mailerDsn || $mailerDsn === 'null://null') {
                $this->logger->warning('MAILER_DSN not configured, cannot send 2FA email');
                return false;
            }

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Code de vérification - Plan B')
                ->htmlTemplate('emails/two-factor.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'code' => $code,
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('2FA email sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send 2FA email', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);
            return false;
        }
    }

    /**
     * Envoie un email de confirmation d'email vérifié
     */
    public function sendEmailVerifiedConfirmation(User $user): bool
    {
        try {
            if (!$this->isConfigured()) {
                $this->logger->warning('MAILER_DSN not configured, cannot send email verified confirmation', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
                return false;
            }

            $email = (new TemplatedEmail())
                ->from($this->fromEmail)
                ->replyTo($this->supportEmail)
                ->to($user->getEmail())
                ->subject('Email vérifié avec succès - Plan B')
                ->htmlTemplate('emails/email-verified.html.twig')
                ->context([
                    'user' => [
                        'firstName' => $user->getFirstName(),
                        'lastName' => $user->getLastName(),
                        'email' => $user->getEmail(),
                    ],
                    'appUrl' => $this->appUrl,
                    'supportEmail' => $this->supportEmail,
                ]);

            $this->mailer->send($email);

            $this->logger->info('Email verified confirmation sent', [
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email verified confirmation', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
                'email' => $user->getEmail()
            ]);
            return false;
        }
    }
}
