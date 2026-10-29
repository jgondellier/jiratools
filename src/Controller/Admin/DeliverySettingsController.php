<?php

namespace App\Controller\Admin;

use App\Delivery\DeliveryService;
use App\Delivery\DeliverySettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DeliverySettingsController extends AbstractController
{
    #[Route('/parametrage/livraisons', name: 'admin_deliveries', methods: ['GET', 'POST'])]
    public function index(Request $request, DeliverySettings $settings, DeliveryService $deliveries): Response
    {
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('deliveries', (string) $request->request->get('_token'))) {
            $settings->save([
                'wsProjects' => DeliverySettings::parseProjects((string) $request->request->get('wsProjects')),
                'wsLabels' => (string) $request->request->get('wsLabels'),
                'myComponents' => array_values(array_filter(
                    DeliverySettings::parseProjects((string) $request->request->get('myComponents')),
                    fn (array $p) => null !== $p['prefix'],
                )),
                'pageId' => (string) $request->request->get('page'),
                'dateColumn' => trim((string) $request->request->get('dateColumn')),
                'versionColumn' => trim((string) $request->request->get('versionColumn')),
                'marginDays' => (int) $request->request->get('marginDays'),
                'horizonDays' => (int) $request->request->get('horizonDays'),
                'startDays' => (int) $request->request->get('startDays'),
            ]);
            if ('' !== trim((string) $request->request->get('page')) && !$settings->getPageId()) {
                $this->addFlash('warning', 'Page Confluence non reconnue : collez l\'URL de la page ou son identifiant numérique.');
            } else {
                $this->addFlash('success', 'Paramétrage enregistré. Vérifiez ci-dessous la lecture de la page.');
            }

            return $this->redirectToRoute('admin_deliveries');
        }

        return $this->render('admin/deliveries.html.twig', [
            'settings' => $settings,
            'projects_text' => DeliverySettings::formatProjects($settings->getWsProjects()),
            'my_components_text' => DeliverySettings::formatProjects($settings->getMyComponents()),
            // Aperçu de la lecture du calendrier, pour valider le paramétrage
            'calendar' => $settings->getPageId() ? $deliveries->getCalendar() : null,
        ]);
    }
}
