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
 * Dépendances livraison : versions non livrées impactées par les tickets de mes projets (projet + composants)
 * et versions des tickets WS qui leur sont liés, avec tout ce qui menace la date (tickets non commencés,
 * bloqués, non assignés, qui traînent, dépendances WS livrées trop tard).
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

        // 1) Mes tickets (projet + composants) dans une version non livrée
        $mine = $this->searchSplit(['mine' => "fixVersion in unreleasedVersions() AND $scope->myJql"]);

        // 2) Tickets WS liés à ces tickets
        $linked = [];
        foreach ($mine as $issue) {
            foreach ($issue->links as $link) {
                if ($scope->isWs($link['projectKey'])) {
                    $linked[$link['key']] = $link['key'];
                }
            }
        }
        $ws = [];
        foreach (array_chunk(array_values($linked), 100) as $i => $chunk) {
            $ws += $this->searchSplit(['ws'.$i => 'key in ('.implode(', ', $chunk).')']);
        }

        // 3) Versions impactées : versions non livrées de ces tickets (mes projets / projets WS)
        $releases = [];
        $issuesByRelease = [];
        foreach ([[$mine, false], [$ws, true]] as [$issues, $isWs]) {
            foreach ($issues as $issue) {
                foreach ($issue->versionDetails as $v) {
                    if ($v['released'] || ($isWs !== $scope->isWs($issue->projectKey))) {
                        continue;
                    }
                    $key = $issue->projectKey.'|'.$v['name'];
                    $releases[$key] ??= $this->createRelease($issue->projectKey, $v, $isWs, $calendar);
                    $issuesByRelease[$key][$issue->key] = $issue;
                }
            }
        }

        // Horizon : versions à livrer dans les N jours, en retard ou sans date
        $horizon = new \DateTimeImmutable(sprintf('today +%d days', $this->settings->getHorizonDays()));
        foreach ($releases as $key => $release) {
            if ($release->getDate() && $release->getDate() > $horizon) {
                unset($releases[$key]);
                continue;
            }
            foreach ($issuesByRelease[$key] as $issueKey => $issue) {
                $release->issues[$issueKey] = $this->views->create($issue);
            }
        }

        $this->evaluate($releases);

        $list = array_values($releases);
        usort($list, fn (ReleaseReadiness $a, ReleaseReadiness $b) => [null === $a->getDate(), $a->getDate(), $a->name] <=> [null === $b->getDate(), $b->getDate(), $b->name]);

        return $list;
    }

    /**
     * Version et sa date : Confluence (référence, avec sa provenance), sinon Jira.
     *
     * @param array{id?: string, name: string, releaseDate: ?\DateTimeImmutable, released: bool} $v
     */
    private function createRelease(string $projectKey, array $v, bool $isWs, DeliveryCalendar $calendar): ReleaseReadiness
    {
        $prefix = $this->settings->getPrefix($projectKey);
        $id = (string) ($v['id'] ?? '');
        $source = $calendar->error ? null : $calendar->source($v['name'], $prefix, '' !== $id ? $id : null);
        $confluenceDate = $source['row']->date ?? null;
        $date = $confluenceDate ?? $v['releaseDate'];

        return new ReleaseReadiness(
            projectKey: $projectKey,
            versionId: $id,
            name: $v['name'],
            isWs: $isWs,
            jiraDate: $v['releaseDate'],
            confluenceDate: $confluenceDate,
            inConfluence: null !== $source,
            daysLeft: $date ? $this->workingTime->remainingDays($date) : null,
            url: '' !== $id ? $this->jira->versionUrl($projectKey, $id) : null,
            otherComponents: $calendar->error || $source ? [] : $calendar->componentsForNumber($v['name'], $prefix),
            source: $source,
        );
    }

    /**
     * Recherches séparées terminés / non terminés (l'historique, pour la durée dans le statut, n'est
     * demandé que pour les tickets non terminés), envoyées en parallèle.
     *
     * @param array<string, string> $jqls
     *
     * @return array<string, Issue> clé du ticket => ticket
     */
    private function searchSplit(array $jqls): array
    {
        $searches = [];
        foreach ($jqls as $key => $jql) {
            $searches[$key.'_open'] = ["$jql AND statusCategory != Done ORDER BY key ASC", 2000, true, ['issuelinks']];
            $searches[$key.'_done'] = ["$jql AND statusCategory = Done ORDER BY key ASC", 2000, false, ['issuelinks']];
        }
        $issues = [];
        foreach ($this->jira->searchMany($searches) as $result) {
            if ($result instanceof AtlassianException) {
                throw $result;
            }
            foreach ($result as $issue) {
                $issues[$issue->key] = $issue;
            }
        }

        return $issues;
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
                $release->addAlert(ReleaseReadiness::WARNING, 'Pas de date de livraison (ni dans Confluence, ni dans Jira).'
                    .DeliveryService::componentsHint($release->otherComponents, $release->projectKey));
            } elseif ($release->isOverdue()) {
                $release->addAlert(ReleaseReadiness::ERROR, sprintf('Date de livraison dépassée (%s) et version non livrée dans Jira.', $date->format('d/m/Y')));
            }
            if ($release->jiraDate && $release->confluenceDate && $release->jiraDate != $release->confluenceDate) {
                $release->addAlert(ReleaseReadiness::WARNING, sprintf('Date Jira %s ≠ date Confluence %s.', $release->jiraDate->format('d/m/Y'), $release->confluenceDate->format('d/m/Y')));
            }
            if ($release->source && $release->source['linkMismatch']) {
                $release->addAlert(ReleaseReadiness::WARNING, sprintf(
                    'Calendrier Confluence, ligne %d : le lien « %s » pointe vers cette version Jira « %s ». Date reprise de cette ligne : vérifiez la page.',
                    $release->source['row']->number, $release->source['linkText'], $release->name,
                ));
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
