<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * « Mises à jour » lance des commandes sur le poste : accessible uniquement depuis le poste
 * lui-même (127.0.0.1), sauf APP_UPDATE_ALLOW_REMOTE=1.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
class LocalOnlyListener
{
    public function __construct(
        #[Autowire('%env(bool:default::APP_UPDATE_ALLOW_REMOTE)%')]
        private readonly ?bool $allowRemote,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $screen = match (true) {
            str_starts_with($path, '/mises-a-jour') => 'Les mises à jour locales',
            default => null,
        };
        if ($this->allowRemote || !$event->isMainRequest() || null === $screen) {
            return;
        }
        if (!\in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            throw new AccessDeniedHttpException($screen.' n\'est accessible que depuis le poste (127.0.0.1). Voir APP_UPDATE_ALLOW_REMOTE.');
        }
    }
}
