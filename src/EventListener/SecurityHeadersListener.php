<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité ajoutés à toutes les pages :
 *  - l'application ne peut pas être affichée dans un cadre d'un autre site (clickjacking) ;
 *  - les formulaires ne peuvent être envoyés qu'à l'application elle-même ;
 *  - le navigateur ne devine pas le type des fichiers ;
 *  - l'adresse des pages (filtres, tickets…) n'est pas transmise aux sites externes ouverts depuis l'application.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
class SecurityHeadersListener
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
    ];

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
