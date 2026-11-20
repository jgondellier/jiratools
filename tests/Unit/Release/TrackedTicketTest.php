<?php

namespace App\Tests\Unit\Release;

use App\Jira\Issue;
use App\Release\TrackedTicket;
use App\Tests\Unit\IssueFactory;
use PHPUnit\Framework\TestCase;

class TrackedTicketTest extends TestCase
{
    private const TAGS = ['v1.4.2', 'v1.4.1'];
    private const DONE = ['status' => 'Terminé', 'statusCategory' => Issue::CATEGORY_DONE];

    public function testInTagMatchingVersion(): void
    {
        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '1', 'name' => '1.4.2 PORTAIL', 'released' => true]]], tags: ['v1.4.2']);

        self::assertSame(TrackedTicket::OK, $ticket->evaluate(self::TAGS, 'develop')->getLevel());
    }

    public function testInTagWithOtherVersion(): void
    {
        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '2', 'name' => '1.5.0']]], tags: ['v1.4.2'])->evaluate(self::TAGS, 'develop');

        self::assertSame(TrackedTicket::ERROR, $ticket->getLevel());
        self::assertStringContainsString('Livré dans le tag v1.4.2 mais la version Jira est « 1.5.0 »', $ticket->problems[0]['message']);
    }

    public function testInTagWithoutVersionOrNotDone(): void
    {
        $ticket = $this->ticket(self::DONE, tags: ['v1.4.2'])->evaluate(self::TAGS, 'develop');
        self::assertStringContainsString('sans version Jira', $ticket->problems[0]['message']);

        $ticket = $this->ticket(['status' => 'En recette', 'versions' => [['id' => '1', 'name' => '1.4.2']]], tags: ['v1.4.2'])->evaluate(self::TAGS, 'develop');
        self::assertSame(TrackedTicket::WARNING, $ticket->getLevel());
        self::assertStringContainsString('toujours « En recette »', $ticket->problems[0]['message']);
    }

    public function testDevelopOnly(): void
    {
        // Version pas encore taggée : partira avec elle
        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '2', 'name' => '1.5.0']]], develop: true);
        self::assertSame(TrackedTicket::OK, $ticket->evaluate(self::TAGS, 'develop')->getLevel());

        // Version déjà taggée : le code est arrivé après le tag
        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '1', 'name' => '1.4.2']]], develop: true)->evaluate(self::TAGS, 'develop');
        self::assertSame(TrackedTicket::ERROR, $ticket->getLevel());
        self::assertStringContainsString('déjà taggée (v1.4.2)', $ticket->problems[0]['message']);

        // Version livrée dans Jira, sans tag lu
        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '0', 'name' => '1.3.0', 'released' => true]]], develop: true)->evaluate(self::TAGS, 'develop');
        self::assertSame(TrackedTicket::ERROR, $ticket->getLevel());

        $ticket = $this->ticket(self::DONE, develop: true)->evaluate(self::TAGS, 'develop');
        self::assertSame(TrackedTicket::WARNING, $ticket->getLevel());
    }

    public function testWithoutCode(): void
    {
        $ticket = $this->ticket(['status' => 'À faire', 'statusCategory' => Issue::CATEGORY_TODO, 'versions' => [['id' => '1', 'name' => '1.4.2']]])->evaluate(self::TAGS, 'develop');
        self::assertSame(TrackedTicket::ERROR, $ticket->getLevel());
        self::assertStringContainsString('aucun commit dans le tag v1.4.2', $ticket->problems[0]['message']);

        $ticket = $this->ticket(['status' => 'À faire', 'statusCategory' => Issue::CATEGORY_TODO, 'versions' => [['id' => '2', 'name' => '1.5.0']]]);
        self::assertSame(TrackedTicket::OK, $ticket->evaluate(self::TAGS, 'develop')->getLevel(), 'Pas encore de code');

        $ticket = $this->ticket(self::DONE + ['versions' => [['id' => '2', 'name' => '1.5.0']]]);
        self::assertSame(TrackedTicket::WARNING, $ticket->evaluate(self::TAGS, 'develop')->getLevel());
    }

    public function testSubTaskUsesParentVersion(): void
    {
        $parent = IssueFactory::create(['key' => 'APP-1', 'versions' => [['id' => '1', 'name' => '1.4.2']]]);
        $ticket = new TrackedTicket('APP-2', IssueFactory::create(['key' => 'APP-2', 'parentKey' => 'APP-1'] + self::DONE), $parent);
        $ticket->tags = ['v1.4.2'];

        self::assertSame(TrackedTicket::OK, $ticket->evaluate(self::TAGS, 'develop')->getLevel());
    }

    private function ticket(array $options, array $tags = [], bool $develop = false): TrackedTicket
    {
        $ticket = new TrackedTicket('APP-1', IssueFactory::create($options));
        $ticket->tags = $tags;
        $ticket->inDevelop = $develop;

        return $ticket;
    }
}
