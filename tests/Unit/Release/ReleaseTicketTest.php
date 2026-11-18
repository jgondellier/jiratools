<?php

namespace App\Tests\Unit\Release;

use App\Jira\Issue;
use App\Release\ReleaseTicket;
use App\Tests\Unit\IssueFactory;
use PHPUnit\Framework\TestCase;

class ReleaseTicketTest extends TestCase
{
    private const TARGET = ['10' => '1.5.0'];

    public function testUpToDate(): void
    {
        $ticket = $this->ticket(['status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE, 'versions' => [['id' => '10', 'name' => '1.5.0']]]);

        self::assertSame(ReleaseTicket::OK, $ticket->evaluate(self::TARGET, [])->getLevel());
        self::assertSame([], $ticket->problems);
    }

    public function testNotFoundInJira(): void
    {
        $ticket = (new ReleaseTicket('APP-404', null))->evaluate(self::TARGET, []);

        self::assertSame(ReleaseTicket::ERROR, $ticket->getLevel());
        self::assertStringContainsString('Introuvable', $ticket->problems[0]['message']);
    }

    public function testStatusByCategoryWhenNoExpectedStatus(): void
    {
        $todo = $this->ticket(['status' => 'À faire', 'statusCategory' => Issue::CATEGORY_TODO, 'versions' => [['id' => '10', 'name' => '1.5.0']]]);
        self::assertSame(ReleaseTicket::ERROR, $todo->evaluate(self::TARGET, [])->getLevel());

        $inProgress = $this->ticket(['status' => 'En recette', 'versions' => [['id' => '10', 'name' => '1.5.0']]]);
        self::assertSame(ReleaseTicket::WARNING, $inProgress->evaluate(self::TARGET, [])->getLevel());
        self::assertStringContainsString('En recette', $inProgress->problems[0]['message']);
    }

    public function testExpectedStatuses(): void
    {
        $ticket = $this->ticket(['status' => 'Prêt pour MEP', 'versions' => [['id' => '10', 'name' => '1.5.0']]]);
        self::assertSame(ReleaseTicket::OK, $ticket->evaluate(self::TARGET, ['prêt pour MEP', 'Terminé'])->getLevel());

        $ticket = $this->ticket(['status' => 'En développement', 'versions' => [['id' => '10', 'name' => '1.5.0']]]);
        self::assertSame(ReleaseTicket::WARNING, $ticket->evaluate(self::TARGET, ['Prêt pour MEP'])->getLevel());
        self::assertStringContainsString('attendu « Prêt pour MEP »', $ticket->problems[0]['message']);
    }

    public function testMissingVersion(): void
    {
        $ticket = $this->ticket(['status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE]);

        self::assertSame(ReleaseTicket::ERROR, $ticket->evaluate(self::TARGET, [])->getLevel());
        self::assertStringContainsString('ajouter « 1.5.0 »', $ticket->problems[0]['message']);

        // Sans version de MEP choisie : simple avertissement
        self::assertSame(ReleaseTicket::WARNING, $ticket->evaluate([], [])->getLevel());
    }

    public function testPlannedInAnotherVersion(): void
    {
        $ticket = $this->ticket(['status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE, 'versions' => [['id' => '11', 'name' => '1.6.0']]]);

        self::assertSame(ReleaseTicket::ERROR, $ticket->evaluate(self::TARGET, [])->getLevel());
        self::assertStringContainsString('Prévu dans « 1.6.0 »', $ticket->problems[0]['message']);
    }

    public function testAlreadyReleasedVersion(): void
    {
        $ticket = $this->ticket(['status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE, 'versions' => [['id' => '9', 'name' => '1.4.0', 'released' => true]]]);

        self::assertSame(ReleaseTicket::WARNING, $ticket->evaluate(self::TARGET, [])->getLevel());
        self::assertStringContainsString('déjà livrée', $ticket->problems[0]['message']);
    }

    public function testSubTaskInheritsParentVersion(): void
    {
        $parent = IssueFactory::create(['key' => 'APP-1', 'versions' => [['id' => '10', 'name' => '1.5.0']]]);
        $subTask = IssueFactory::create(['key' => 'APP-2', 'status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE, 'parentKey' => 'APP-1']);

        self::assertSame(ReleaseTicket::OK, (new ReleaseTicket('APP-2', $subTask, $parent))->evaluate(self::TARGET, [])->getLevel());

        $otherParent = IssueFactory::create(['key' => 'APP-1', 'versions' => [['id' => '11', 'name' => '1.6.0']]]);
        $ticket = (new ReleaseTicket('APP-2', $subTask, $otherParent))->evaluate(self::TARGET, []);
        self::assertSame(ReleaseTicket::ERROR, $ticket->getLevel());
        self::assertStringContainsString('ticket parent APP-1', $ticket->problems[0]['message']);
    }

    public function testSourcesWithoutDuplicates(): void
    {
        $ticket = $this->ticket([]);
        $source = ['repository' => 'front', 'type' => 'mr', 'label' => '!1 Export', 'url' => 'https://gitlab.test/mr/1', 'author' => 'Alice', 'date' => null];
        $ticket->addSource($source);
        $ticket->addSource($source);

        self::assertCount(1, $ticket->sources);
    }

    private function ticket(array $options): ReleaseTicket
    {
        return new ReleaseTicket('APP-1', IssueFactory::create($options));
    }
}
