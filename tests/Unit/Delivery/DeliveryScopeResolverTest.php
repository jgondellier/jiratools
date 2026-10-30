<?php

namespace App\Tests\Unit\Delivery;

use App\Atlassian\AtlassianException;
use App\Delivery\DeliveryScopeResolver;
use App\Delivery\DeliverySettings;
use App\Entity\Project;
use App\Jira\JiraClient;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;

class DeliveryScopeResolverTest extends TestCase
{
    private function resolver(JiraClient $jira, array $settings = []): DeliveryScopeResolver
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getValue')->willReturn($settings);

        return new DeliveryScopeResolver($jira, new DeliverySettings($repo));
    }

    public function testScopeFromBoardFilter(): void
    {
        $jira = $this->createMock(JiraClient::class);
        $jira->expects(self::once())->method('getBoardScope')->with(42)->willReturn([
            'id' => 42, 'name' => 'Board équipe', 'jql' => 'project = APP OR (project = WS AND labels = equipe-portail)', 'projects' => ['APP', 'WS'],
        ]);

        $scope = $this->resolver($jira)->resolve([
            (new Project())->setJiraProjectKey('APP')->setJiraBoardId(42),
            (new Project())->setJiraProjectKey('APP')->setJiraBoardId(42)->setComponents(['API']),
        ]);

        self::assertSame(['APP'], $scope->myKeys);
        self::assertSame(['WS'], $scope->wsKeys, 'projet du board autre que les miens');
        self::assertSame(['equipe-portail'], $scope->labels, 'étiquette lue dans le filtre');
        self::assertTrue($scope->isWs('WS'));
        self::assertTrue($scope->hasWsLabel(['Equipe-Portail']));
        self::assertSame(
            '((project = APP OR (project = WS AND labels = equipe-portail))) AND (((project = "APP") OR (project = "APP" AND component in ("API"))) OR project in ("WS"))',
            $scope->issuesJql(),
        );
        self::assertSame('((project = APP OR (project = WS AND labels = equipe-portail))) AND project in ("WS")', $scope->wsTrackedJql());
    }

    public function testFallbackOnSettingsWhenBoardUnreadable(): void
    {
        $jira = $this->createMock(JiraClient::class);
        $jira->method('getBoardScope')->willThrowException(new AtlassianException('Jira a répondu 403', 403));

        $scope = $this->resolver($jira, ['wsProjects' => [['key' => 'WS', 'prefix' => null]], 'wsLabels' => ['equipe']])
            ->resolve([(new Project())->setJiraProjectKey('APP')->setJiraBoardId(42)]);

        self::assertSame(['WS'], $scope->wsKeys);
        self::assertSame(['Board 42 : Jira a répondu 403'], $scope->errors);
        self::assertSame('(((project = "APP")) OR (project in ("WS") AND labels in ("equipe")))', $scope->issuesJql());
        self::assertSame('project in ("WS") AND labels in ("equipe")', $scope->wsTrackedJql());
    }
}
