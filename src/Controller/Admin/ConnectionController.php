<?php

namespace App\Controller\Admin;

use App\Atlassian\AtlassianException;
use App\Confluence\ConfluenceClient;
use App\Jira\JiraClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConnectionController extends AbstractController
{
    #[Route('/parametrage/connexion', name: 'admin_connection', methods: ['GET'])]
    public function index(
        JiraClient $jira,
        ConfluenceClient $confluence,
        #[Autowire(env: 'bool:APP_HTTP_VERIFY_PEER')] bool $verifyPeer,
        #[Autowire(env: 'bool:APP_HTTP_VERIFY_HOST')] bool $verifyHost,
        #[Autowire(env: 'APP_HTTP_CAFILE')] string $cafile,
        #[Autowire(env: 'APP_HTTP_PROXY')] string $proxy,
        #[Autowire(env: 'APP_HTTP_NO_PROXY')] string $noProxy,
        #[Autowire(env: 'float:APP_HTTP_TIMEOUT')] float $timeout,
    ): Response {
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

        // Le mot de passe éventuel du proxy n'est pas affiché
        $proxyDisplay = preg_replace('#//[^/@]*@#', '//***@', $proxy);

        return $this->render('admin/connection.html.twig', [
            'checks' => $checks,
            'http_options' => [
                'APP_HTTP_VERIFY_PEER' => $verifyPeer ? 'oui' : 'NON (certificat non vérifié)',
                'APP_HTTP_VERIFY_HOST' => $verifyHost ? 'oui' : 'NON (nom d\'hôte non vérifié)',
                'APP_HTTP_CAFILE' => '' !== $cafile ? $cafile.(is_readable($cafile) ? '' : ' — fichier introuvable !') : 'magasin par défaut de PHP',
                'APP_HTTP_PROXY' => $proxyDisplay ?: 'aucun',
                'APP_HTTP_NO_PROXY' => $noProxy ?: '—',
                'APP_HTTP_TIMEOUT' => $timeout.' s',
            ],
        ]);
    }
}
