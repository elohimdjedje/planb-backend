<?php

namespace App\Service;

use App\Entity\SecurityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class SecurityLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function logLogin(User $user, Request $request): void
    {
        $this->log(
            user: $user,
            action: 'login',
            request: $request,
            severity: 'info',
            context: [
                'email' => $user->getEmail()
            ]
        );
    }

    public function logFailedLogin(string $email, Request $request): void
    {
        $this->log(
            user: null,
            action: 'failed_login',
            request: $request,
            severity: 'warning',
            context: [
                'email' => $email
            ]
        );
    }

    public function logPasswordChange(User $user, Request $request): void
    {
        $this->log(
            user: $user,
            action: 'password_change',
            request: $request,
            severity: 'info'
        );
    }

    public function logRegister(User $user, Request $request): void
    {
        $this->log(
            user: $user,
            action: 'register',
            request: $request,
            severity: 'info',
            context: [
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'country' => $user->getCountry()
            ]
        );
    }

    public function logLogout(User $user, Request $request): void
    {
        $this->log(
            user: $user,
            action: 'logout',
            request: $request,
            severity: 'info'
        );
    }

    public function logSuspiciousActivity(
        ?User $user,
        string $action,
        Request $request,
        array $context = []
    ): void {
        $this->log(
            user: $user,
            action: $action,
            request: $request,
            severity: 'critical',
            context: $context
        );
    }

    public function logProfileUpdate(User $user, Request $request, array $changes): void
    {
        $this->log(
            user: $user,
            action: 'profile_update',
            request: $request,
            severity: 'info',
            context: ['changes' => $changes]
        );
    }

    public function logAccountDeletion(User $user, Request $request): void
    {
        $this->log(
            user: $user,
            action: 'account_deletion',
            request: $request,
            severity: 'warning',
            context: [
                'email' => $user->getEmail()
            ]
        );
    }

    /**
     * Audit des actions admin sensibles (OWASP A09 — Logging & Monitoring).
     * Toute modification de rôle, suppression ou action privilégiée doit être tracée.
     *
     * @param User   $admin    L'administrateur qui effectue l'action
     * @param string $action   Ex: 'promote_admin', 'demote_admin', 'delete_user', 'approve_listing'
     * @param array  $context  Données associées (cible, avant/après, raison…)
     */
    public function logAdminAction(User $admin, string $action, Request $request, array $context = []): void
    {
        $this->log(
            user: $admin,
            action: 'admin_' . $action,
            request: $request,
            severity: 'warning',
            context: array_merge($context, [
                'admin_email' => $admin->getEmail(),
                'admin_id'    => $admin->getId(),
            ])
        );
    }

    private function log(
        ?User $user,
        string $action,
        Request $request,
        string $severity = 'info',
        array $context = []
    ): void {
        $log = new SecurityLog();
        
        if ($user) {
            $log->setUser($user);
        }
        
        $log->setAction($action);
        $log->setIpAddress($request->getClientIp() ?? 'unknown');
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setSeverity($severity);
        $log->setContext($context);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function hasExcessiveFailedAttempts(string $ipAddress, int $maxAttempts = 5, int $minutes = 15): bool
    {
        $since = new \DateTimeImmutable("-{$minutes} minutes");
        
        $count = $this->entityManager->getRepository(SecurityLog::class)
            ->countFailedLoginsByIp($ipAddress, $since);

        return $count >= $maxAttempts;
    }
}
