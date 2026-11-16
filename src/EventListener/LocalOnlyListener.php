<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * « Mises à jour » lance des commandes sur le poste : accessible uniquement depuis
 * le poste lui-même (127.0.0.1), sauf LOCAL_UPDATE_ALLOW_REMOTE=1.
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
        if ($this->allowRemote || !$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/mises-a-jour')) {
            return;
        }
        if (!\in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            throw new AccessDeniedHttpException('Les mises à jour locales ne sont accessibles que depuis le poste (127.0.0.1). Voir APP_UPDATE_ALLOW_REMOTE.');
        }
    }
}
