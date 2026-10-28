<?php

namespace App\EventListener;

use App\Atlassian\AtlassianException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Affiche une page explicite (plutôt qu'une erreur 500) quand Jira ou Confluence ne répond pas.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class AtlassianExceptionListener
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        while ($exception && !$exception instanceof AtlassianException) {
            $exception = $exception->getPrevious();
        }
        if (!$exception) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('error_atlassian.html.twig', ['message' => $exception->getMessage()]),
            Response::HTTP_BAD_GATEWAY,
        ));
    }
}
