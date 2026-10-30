<?php

namespace App\Delivery;

use App\Atlassian\AtlassianException;
use App\Entity\Project;
use App\Jira\Issue;
use App\Jira\JiraClient;
use App\Service\IssueView;
use App\Service\IssueViewFactory;
use App\Service\StatusAge;
use App\Service\WorkingTime;

/**
 * Prochaines livraisons : versions non livrées de mes projets et des projets WS, leurs tickets et
 * tout ce qui menace la date (tickets non commencés, bloqués, non assignés, qui traînent, dépendances WS).
 */
class ReleaseReadinessService
{
    public function __construct(
        private readonly DeliverySettings $settings,
        private readonly DeliveryScopeResolver $scopeResolver,
        private readonly JiraClient $jira,
        private readonly IssueViewFactory $views,
        private readonly WorkingTime $workingTime,
    ) {
    }

    /**
     * @param Project[] $projects mes projets (le filtre par composant s'applique à leurs tickets)
     *
     * @return ReleaseReadiness[] triées par date de livraison (sans date à la fin)
     */
    public function getUpcoming(array $projects, DeliveryCalendar $calendar, ?DeliveryScope $scope = null): array
    {
        $scope ??= $this->scopeResolver->resolve($projects);
        if ($scope->isEmpty()) {
            return [];
        }

        $releases = $this->selectVersions(array_merge($scope->myKeys, $scope->wsKeys), $scope->wsKeys, $calendar);
        if ([] === $releases) {
            return [];
        }
        $this->loadIssues($releases, $scope);
        $this->evaluate($releases);

        $list = array_values($releases);
        usort($list, fn (ReleaseReadiness $a, ReleaseReadiness $b) => [null === $a->getDate(), $a->getDate(), $a->name] <=> [null === $b->getDate(), $b->getDate(), $b->name]);

        return $list;
    }

    /**
     * Versions non livrées et non archivées, dont la date (Confluence, sinon Jira) tombe dans l'horizon,
     * en retard, ou sans date.
     *
     * @return array<string, ReleaseReadiness> clé "projet|version"
     */
    private function selectVersions(array $keys, array $wsKeys, DeliveryCalendar $calendar): array
    {
        $horizon = new \DateTimeImmutable(sprintf('today +%d days', $this->settings->getHorizonDays()));
        $releases = [];
        foreach ($this->jira->getVersionsMany($keys) as $projectKey => $versions) {
            $prefix = $this->settings->getPrefix($projectKey);
            foreach ($versions as $v) {
                if ($v['released'] || $v['archived']) {
                    continue;
                }
                $confluenceDate = $calendar->error ? null : $calendar->dateOf($v['name'], $prefix, $v['id']);
                $date = $confluenceDate ?? $v['releaseDate'];
                if ($date && $date > $horizon) {
                    continue;
                }
                $releases[$projectKey.'|'.$v['name']] = new ReleaseReadiness(
                    projectKey: $projectKey,
                    versionId: $v['id'],
                    name: $v['name'],
                    isWs: \in_array($projectKey, $wsKeys, true),
                    jiraDate: $v['releaseDate'],
                    confluenceDate: $confluenceDate,
                    inConfluence: !$calendar->error && [] !== $calendar->find($v['name'], $prefix, $v['id']),
                    otherComponents: $calendar->error ? [] : $calendar->componentsForNumber($v['name'], $prefix),
                    daysLeft: $date ? $this->workingTime->remainingDays($date) : null,
                    url: $this->jira->versionUrl($projectKey, $v['id']),
                );
            }
        }

        return $releases;
    }

    /**
     * Tickets des versions retenues, dans le périmètre du board (mon projet + tickets WS du board),
     * en un seul lot de recherches. L'historique (pour la durée dans le statut) n'est demandé que pour
     * les tickets non terminés.
     *
     * @param array<string, ReleaseReadiness> $releases
     */
    private function loadIssues(array $releases, DeliveryScope $scope): void
    {
        $ids = array_map(fn (ReleaseReadiness $r) => $r->versionId, array_values($releases));
        $jql = sprintf('fixVersion in (%s) AND %s', implode(', ', $ids), $scope->issuesJql());

        $results = $this->jira->searchMany([
            'open' => ["$jql AND statusCategory != Done ORDER BY key ASC", 2000, true, ['issuelinks']],
            'done' => ["$jql AND statusCategory = Done ORDER BY key ASC", 2000, false],
        ]);

        foreach ($results as $result) {
            if ($result instanceof AtlassianException) {
                throw $result;
            }
            foreach ($result as $issue) {
                $view = $this->views->create($issue);
                foreach ($issue->versionDetails as $v) {
                    // Indexé par clé : un ticket n'apparaît qu'une fois par version
                    if (isset($releases[$issue->projectKey.'|'.$v['name']])) {
                        $releases[$issue->projectKey.'|'.$v['name']]->issues[$issue->key] = $view;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, ReleaseReadiness> $releases
     */
    private function evaluate(array $releases): void
    {
        $startDays = $this->settings->getStartDays();

        // Date de livraison de chaque ticket WS connu (pour les dépendances de mes tickets)
        $wsDelivery = [];
        foreach ($releases as $release) {
            if ($release->isWs) {
                foreach ($release->issues as $view) {
                    $wsDelivery[$view->issue->key][] = $release;
                }
            }
        }

        foreach ($releases as $release) {
            $date = $release->getDate();

            if (null === $date) {
                $release->addAlert(ReleaseReadiness::WARNING, 'Pas de date de livraison (ni dans Confluence, ni dans Jira).');
            } elseif ($release->isOverdue()) {
                $release->addAlert(ReleaseReadiness::ERROR, sprintf('Date de livraison dépassée (%s) et version non livrée dans Jira.', $date->format('d/m/Y')));
            }
            if ($release->jiraDate && $release->confluenceDate && $release->jiraDate != $release->confluenceDate) {
                $release->addAlert(ReleaseReadiness::WARNING, sprintf('Date Jira %s ≠ date Confluence %s.', $release->jiraDate->format('d/m/Y'), $release->confluenceDate->format('d/m/Y')));
            }
            if (!$release->inConfluence && $release->jiraDate) {
                $release->addAlert(ReleaseReadiness::WARNING, 'Absente du calendrier Confluence.'.DeliveryService::componentsHint($release->otherComponents, $release->projectKey));
            }

            foreach ($release->issues as $view) {
                $this->evaluateIssue($release, $view, $startDays, $wsDelivery);
            }
        }
    }

    /**
     * @param array<string, ReleaseReadiness[]> $wsDelivery
     */
    private function evaluateIssue(ReleaseReadiness $release, IssueView $view, int $startDays, array $wsDelivery): void
    {
        $issue = $view->issue;
        if ($issue->isDone()) {
            return;
        }
        $days = $release->daysLeft;

        if ($release->isOverdue()) {
            $release->addIssueAlert($issue, ReleaseReadiness::ERROR, 'Non terminé alors que la date de livraison est passée.');
        } elseif (null !== $days && $issue->isTodo()) {
            if ($days <= $startDays) {
                $release->addIssueAlert($issue, ReleaseReadiness::ERROR, sprintf('Pas commencé à %d jour(s) ouvré(s) de la livraison.', $days));
            } elseif ($days <= 2 * $startDays) {
                $release->addIssueAlert($issue, ReleaseReadiness::WARNING, sprintf('Pas encore commencé (livraison dans %d jours ouvrés).', $days));
            }
        }

        if ($issue->flagged) {
            $release->addIssueAlert($issue, ReleaseReadiness::ERROR, 'Signalé bloqué (drapeau).');
        }
        if (null === $issue->assigneeId) {
            $release->addIssueAlert($issue, ReleaseReadiness::WARNING, 'Personne n\'est assigné.');
        }
        if ($view->age->isAlert()) {
            $release->addIssueAlert(
                $issue,
                StatusAge::CRITICAL === $view->age->level ? ReleaseReadiness::ERROR : ReleaseReadiness::WARNING,
                sprintf('Dans « %s » depuis %s.', $issue->status, $view->age->getLabel()),
            );
        }

        // Mes tickets qui dépendent d'un ticket WS livré après ma version
        if (!$release->isWs && $release->getDate()) {
            foreach ($issue->links as $link) {
                foreach ($wsDelivery[$link['key']] ?? [] as $wsRelease) {
                    $wsDate = $wsRelease->getDate();
                    if ($wsDate && $wsDate > $release->getDate()) {
                        $release->addIssueAlert($issue, ReleaseReadiness::ERROR, sprintf(
                            'Lié à %s, livré en %s le %s (après cette version).',
                            $link['key'], $wsRelease->name, $wsDate->format('d/m/Y'),
                        ));
                    }
                }
            }
        }
    }
}
