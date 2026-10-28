<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Service\ProjectScope;
use PHPUnit\Framework\TestCase;

class ProjectScopeTest extends TestCase
{
    public function testJqlWithComponentsAndExtra(): void
    {
        $project = (new Project())->setJiraProjectKey('app')->setComponentsText("Portail\nAPI \"v2\", ,Back")->setExtraJql('issuetype != Epic');

        self::assertSame(['Portail', 'API "v2"', 'Back'], $project->getComponents());
        self::assertSame(
            'project = "APP" AND component in ("Portail", "API \"v2\"", "Back") AND (issuetype != Epic)',
            ProjectScope::jql($project),
        );
    }

    public function testFindProjectByComponent(): void
    {
        $portail = (new Project())->setName('Portail')->setJiraProjectKey('APP')->setComponents(['Portail']);
        $api = (new Project())->setName('API')->setJiraProjectKey('APP')->setComponents(['API']);
        $whole = (new Project())->setName('Tout')->setJiraProjectKey('APP');

        $issue = IssueFactory::create(['components' => ['api']]);
        self::assertSame($api, ProjectScope::findProject($issue, [$portail, $api, $whole]));

        $noComponent = IssueFactory::create();
        self::assertSame($whole, ProjectScope::findProject($noComponent, [$portail, $api, $whole]));
        self::assertNull(ProjectScope::findProject(IssueFactory::create(['projectKey' => 'XYZ']), [$portail]));
    }
}
