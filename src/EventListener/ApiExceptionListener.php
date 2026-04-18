<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
class ApiExceptionListener
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        $statusCode = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AccessDeniedException => ($this->tokenStorage->getToken()?->getUser() !== null) ? 403 : 401,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        $data = [
            'code' => $statusCode,
            'message' => $exception->getMessage(),
        ];

        if ($statusCode === 500) {
            $data['debug'] = [
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
            error_log('[API 500] ' . get_class($exception) . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        }

        if ($statusCode === 401) {
            $data['message'] = 'Authentication requise.';
        }

        if ($statusCode === 403) {
            $data['message'] = 'Accès refusé. Permissions insuffisantes.';
        }

        $response = new JsonResponse($data, $statusCode);
        $response->headers->set('Content-Type', 'application/json');

        $event->setResponse($response);
    }
}
