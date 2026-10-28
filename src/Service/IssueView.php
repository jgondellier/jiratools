<?php

namespace App\Service;

use App\Entity\Project;
use App\Jira\Issue;

/**
 * Ticket enrichi pour l'affichage : âge dans le statut et projet applicatif.
 */
final class IssueView
{
    public function __construct(
        public readonly Issue $issue,
        public readonly StatusAge $age,
        public readonly ?Project $project,
    ) {
    }
}
