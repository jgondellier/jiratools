<?php

namespace App\Controller\Admin;

use App\Atlassian\AtlassianException;
use App\Confluence\ConfluenceClient;
use App\Jira\JiraClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConnectionController extends AbstractController
{
    #[Route('/parametrage/connexion', name: 'admin_connection', methods: ['GET'])]
    public function index(JiraClient $jira, ConfluenceClient $confluence): Response
    {
        $checks = [];

        try {
            $me = $jira->myself();
            $checks['Jira'] = ['ok' => true, 'message' => sprintf('Connecté en tant que %s (%s)', $me['displayName'] ?? '?', $jira->isCloud() ? 'Cloud' : 'Server / Data Center')];
        } catch (AtlassianException $e) {
            $checks['Jira'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        try {
            $me = $confluence->currentUser();
            $checks['Confluence'] = ['ok' => true, 'message' => sprintf('Connecté en tant que %s', $me['displayName'] ?? $me['username'] ?? '?')];
        } catch (AtlassianException $e) {
            $checks['Confluence'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        return $this->render('admin/connection.html.twig', ['checks' => $checks]);
    }
}
