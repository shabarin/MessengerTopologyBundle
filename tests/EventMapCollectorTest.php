<?php

namespace Shabarin\MessengerTopologyBundle\Tests;

use Shabarin\MessengerTopologyBundle\Conventions;
use Shabarin\MessengerTopologyBundle\EventMap;
use Shabarin\MessengerTopologyBundle\EventMapCollector;
use Shabarin\MessengerTopologyBundle\MermaidEventMapRenderer;
use Shabarin\MessengerTopologyBundle\MessageNode;
use Shabarin\MessengerTopologyBundle\Participant;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessage\Beta\DoThing;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessageHandler\Beta\DoThingHandler;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\EventListener\Beta\ThingFailedListener;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha\OrphanEvent;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha\ThingHappenedEvent;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\MessageHandler\Beta\ThingHappenedEventHandler;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Service\Alpha\AlphaService;
use PHPUnit\Framework\TestCase;

class EventMapCollectorTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/EventMap';

    public function testCollectsPublishersHandlersListenersAndRouting(): void
    {
        $map = $this->collect();

        self::assertSame([DoThing::class, OrphanEvent::class, ThingHappenedEvent::class], array_map(fn (MessageNode $m) => $m->class, $map->messages));

        $event = $this->message($map->messages, ThingHappenedEvent::class);
        self::assertSame('Alpha', $event->module);
        self::assertSame(MessageNode::KIND_EVENT, $event->kind);
        self::assertSame('async', $event->transport);
        self::assertSame('sync', $event->transportInTests);
        self::assertSame([[AlphaService::class, 'Alpha']], $this->pairs($event->publishers));
        self::assertSame([[ThingHappenedEventHandler::class, 'Beta']], $this->pairs($event->handlers));
        self::assertSame('event.bus', $event->handlers[0]->bus);
        self::assertSame([[ThingFailedListener::class, 'Beta']], $this->pairs($event->failureListeners));
    }

    public function testCommandIsRecognisedByBusAndNamespaceAndMethodLevelAttribute(): void
    {
        $command = $this->message($this->collect()->messages, DoThing::class);

        self::assertSame(MessageNode::KIND_COMMAND, $command->kind);
        self::assertSame('Beta', $command->module);
        self::assertSame('sync', $command->transport, 'no prod route means sync');
        self::assertSame('async, async_notifications', $command->transportInTests, 'a list of transports is joined into one string');
        self::assertSame([[DoThingHandler::class, 'Beta']], $this->pairs($command->handlers));
        self::assertSame('command.bus', $command->handlers[0]->bus);
        self::assertSame([[AlphaService::class, 'Alpha']], $this->pairs($command->publishers));
    }

    public function testOrphanMessageIsReportedWithoutPublishersAndHandlers(): void
    {
        $map = $this->collect();
        $orphan = $this->message($map->messages, OrphanEvent::class);

        self::assertSame('sync', $orphan->transport);
        self::assertSame([], $orphan->publishers);
        self::assertSame([], $orphan->handlers);
        self::assertSame([OrphanEvent::class], array_map(fn (MessageNode $m) => $m->class, $map->withoutHandlers()));
        self::assertSame([OrphanEvent::class], array_map(fn (MessageNode $m) => $m->class, $map->withoutPublishers()));
        self::assertSame(['Alpha', 'Beta'], $map->modules());
    }

    public function testRendererDrawsCrossModuleEdgesAndFindings(): void
    {
        $markdown = (new MermaidEventMapRenderer())->render($this->collect());

        self::assertStringContainsString('Alpha -- "ThingHappenedEvent" --> Beta', $markdown, 'async in prod gives a solid arrow; the sync route of the tests does not matter');
        self::assertStringContainsString('Alpha -. "DoThing" .-> Beta', $markdown, 'sync gives a dashed arrow');
        self::assertStringContainsString('subgraph m_Alpha["Alpha"]', $markdown);
        self::assertStringContainsString('{{"ThingHappenedEvent"}}', $markdown);
        self::assertStringContainsString('(["DoThing"])', $markdown);
        self::assertStringContainsString('"retries exhausted" .-> Shabarin_MessengerTopologyBundle_Tests_Fixtures_EventMap_EventListener_Beta_ThingFailedListener', $markdown);
        self::assertStringContainsString('- `OrphanEvent` (Alpha)', $markdown);
        self::assertStringContainsString('class Shabarin_MessengerTopologyBundle_Tests_Fixtures_EventMap_Message_Alpha_OrphanEvent unhandled', $markdown);
        self::assertSame($markdown, (new MermaidEventMapRenderer())->render($this->collect()), 'the output is deterministic');
    }

    public function testEveryDiagramAsksForElkLayout(): void
    {
        $markdown = (new MermaidEventMapRenderer())->render($this->collect());

        self::assertSame(
            substr_count($markdown, '```mermaid'),
            substr_count($markdown, "```mermaid\n%%{init: {\"layout\": \"elk\"}}%%\nflowchart "),
            'every diagram asks for the ELK layout (without it Mermaid falls back to dagre by itself)',
        );
        self::assertStringContainsString("flowchart TB\n    Alpha[", $markdown, 'the overview is top to bottom, no horizontal scrolling');
        self::assertStringContainsString("flowchart LR\n    subgraph", $markdown, 'module diagrams are left to right');
    }

    public function testOverviewDeclaresModulesInFlowOrder(): void
    {
        $markdown = (new MermaidEventMapRenderer())->render($this->collect());
        $overview = $this->section($markdown, '## Overview', '## Messages');

        self::assertLessThan(strpos($overview, 'Beta["Beta"]'), strpos($overview, 'Alpha["Alpha"]'), 'the publisher is declared before the receiver');
    }

    private function section(string $markdown, string $from, string $to): string
    {
        $start = strpos($markdown, $from);
        $end = strpos($markdown, $to, $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($markdown, $start, $end - $start);
    }

    private function collect(): EventMap
    {
        return (new EventMapCollector(self::FIXTURES, self::FIXTURES.'/messenger.yaml', new Conventions(
            rootNamespace: 'Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap',
            messageLayers: ['Message', 'CommandMessage'],
            commandLayers: ['CommandMessage'],
            moduleLayers: ['Service', 'Message', 'MessageHandler', 'CommandMessage', 'CommandMessageHandler'],
        )))->collect();
    }

    /**
     * @param list<MessageNode> $messages
     */
    private function message(array $messages, string $class): MessageNode
    {
        foreach ($messages as $message) {
            if ($message->class === $class) {
                return $message;
            }
        }
        self::fail(sprintf('%s not collected', $class));
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $participants): array
    {
        return array_map(fn (Participant $p) => [$p->class, $p->module], $participants);
    }
}
