<?php

namespace Shabarin\MessengerTopologyBundle\Tests;

use Shabarin\MessengerTopologyBundle\EventMap;
use Shabarin\MessengerTopologyBundle\EventMapDiff;
use Shabarin\MessengerTopologyBundle\Labels;
use Shabarin\MessengerTopologyBundle\MermaidEventMapDiffRenderer;
use Shabarin\MessengerTopologyBundle\MermaidEventMapRenderer;
use Shabarin\MessengerTopologyBundle\MessageChange;
use Shabarin\MessengerTopologyBundle\MessageNode;
use Shabarin\MessengerTopologyBundle\Participant;
use PHPUnit\Framework\TestCase;

class EventMapDiffTest extends TestCase
{
    public function testClassifiesEveryKindOfChange(): void
    {
        $base = new EventMap([
            $this->node('App\Message\Booking\Gone', 'Booking', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Chat\GoneHandler' => 'Chat']),
            $this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService']),
            $this->node('App\Message\Booking\Switched', 'Booking', transport: 'sync', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Billing\SwitchedHandler' => 'Billing']),
            $this->node('App\Message\Booking\TestsOnly', 'Booking', test: 'sync', publishers: ['App\Service\Booking\BookingService']),
        ]);
        $head = new EventMap([
            $this->node('App\Message\Booking\Fresh', 'Booking', publishers: ['App\Service\Booking\OfferService'], handlers: ['App\MessageHandler\Search\FreshHandler' => 'Search']),
            $this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService']),
            $this->node('App\Message\Booking\Switched', 'Booking', transport: 'async', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Billing\SwitchedHandler' => 'Billing', 'App\MessageHandler\Invoice\SwitchedHandler' => 'Invoice']),
            $this->node('App\Message\Booking\TestsOnly', 'Booking', test: 'async', publishers: ['App\Service\Booking\BookingService']),
        ]);

        $changes = EventMapDiff::between($base, $head);

        self::assertFalse($changes->isEmpty());
        self::assertSame(
            ['App\Message\Booking\Fresh' => MessageChange::ADDED, 'App\Message\Booking\Gone' => MessageChange::REMOVED, 'App\Message\Booking\Same' => MessageChange::UNCHANGED, 'App\Message\Booking\Switched' => MessageChange::CHANGED, 'App\Message\Booking\TestsOnly' => MessageChange::TESTS_ONLY],
            array_combine(array_map(fn (MessageChange $c) => $c->class, $changes->changes), array_map(fn (MessageChange $c) => $c->status, $changes->changes)),
            'sorted by class; a test-only transport change is a separate, quiet status',
        );

        $switched = $changes->change('App\Message\Booking\Switched');
        self::assertSame(['sync', 'async'], $switched->transport);
        self::assertNull($switched->transportInTests);
        self::assertSame(['App\MessageHandler\Invoice\SwitchedHandler'], array_map(fn (Participant $p) => $p->class, $switched->addedHandlers));
        self::assertSame([], $switched->removedHandlers);
        self::assertSame('async', $switched->merged->transport, 'the merged node takes its properties from head');

        $gone = $changes->change('App\Message\Booking\Gone');
        self::assertSame('App\Message\Booking\Gone', $gone->merged->class, 'a removed message stays in the merged map as a ghost');
        self::assertSame(['sync', 'async'], $changes->change('App\Message\Booking\TestsOnly')->transportInTests);

        self::assertSame(
            ['App\Message\Booking\Fresh', 'App\Message\Booking\Gone', 'App\Message\Booking\Same', 'App\Message\Booking\Switched', 'App\Message\Booking\TestsOnly'],
            array_map(fn (MessageNode $m) => $m->class, $changes->union()->messages),
        );
        self::assertSame(
            ['App\MessageHandler\Billing\SwitchedHandler', 'App\MessageHandler\Invoice\SwitchedHandler'],
            array_map(fn (Participant $p) => $p->class, $changes->change('App\Message\Booking\Switched')->merged->handlers),
            'participants are merged: head first, then base-only ones',
        );
        self::assertSame(MessageChange::ADDED, $changes->participantStatus('App\Service\Booking\OfferService'));
        self::assertSame(MessageChange::REMOVED, $changes->participantStatus('App\MessageHandler\Chat\GoneHandler'));
        self::assertSame(MessageChange::UNCHANGED, $changes->participantStatus('App\Service\Booking\BookingService'));
    }

    public function testLabelsCanBeReplaced(): void
    {
        $map = new EventMap([$this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService'])]);
        $labels = new Labels(diffTitle: '# Changements', diffComparedTo: 'Par rapport à %s.', diffNoChanges: 'Aucun changement.');

        $markdown = (new MermaidEventMapDiffRenderer(new MermaidEventMapRenderer($labels), $labels))->render(EventMapDiff::between($map, $map), 'main');

        self::assertStringContainsString("# Changements\n\nPar rapport à main.\n\nAucun changement.", $markdown);
    }

    public function testIdenticalMapsProduceNoChanges(): void
    {
        $map = new EventMap([$this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService'])]);

        $changes = EventMapDiff::between($map, $map);

        self::assertTrue($changes->isEmpty());
        $markdown = (new MermaidEventMapDiffRenderer())->render($changes, 'main (abc123)');
        self::assertStringContainsString('No changes.', $markdown);
        self::assertStringNotContainsString('```mermaid', $markdown);
    }

    public function testRendererListsChangesAndHighlightsThemOnTheDiagrams(): void
    {
        $base = new EventMap([
            $this->node('App\Message\Booking\Gone', 'Booking', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Chat\GoneHandler' => 'Chat']),
            $this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Chat\SameHandler' => 'Chat']),
            $this->node('App\Message\Booking\Switched', 'Booking', transport: 'sync', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Billing\SwitchedHandler' => 'Billing']),
            $this->node('App\Message\Booking\TestsOnly', 'Booking', test: 'sync', publishers: ['App\Service\Booking\BookingService']),
            $this->node('App\Message\Review\Untouched', 'Review', publishers: ['App\Service\Review\ReviewService']),
        ]);
        $head = new EventMap([
            $this->node('App\Message\Booking\Fresh', 'Booking', publishers: ['App\Service\Booking\OfferService'], handlers: ['App\MessageHandler\Search\FreshHandler' => 'Search']),
            $this->node('App\Message\Booking\Same', 'Booking', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Chat\SameHandler' => 'Chat']),
            $this->node('App\Message\Booking\Switched', 'Booking', transport: 'async', publishers: ['App\Service\Booking\BookingService'], handlers: ['App\MessageHandler\Billing\SwitchedHandler' => 'Billing', 'App\MessageHandler\Invoice\SwitchedHandler' => 'Invoice']),
            $this->node('App\Message\Booking\TestsOnly', 'Booking', test: 'async', publishers: ['App\Service\Booking\BookingService']),
            $this->node('App\Message\Review\Untouched', 'Review', publishers: ['App\Service\Review\ReviewService']),
        ]);

        $markdown = (new MermaidEventMapDiffRenderer())->render(EventMapDiff::between($base, $head), 'main (abc123)');

        self::assertStringContainsString('main (abc123)', $markdown, 'what it was compared with');
        // The text list reads even without Mermaid.
        self::assertStringContainsString("### Added\n\n- `Fresh` (Booking): published by OfferService (Booking); handled by FreshHandler (Search)", $markdown);
        self::assertStringContainsString("### Removed\n\n- `Gone` (Booking)", $markdown);
        self::assertStringContainsString("### Changed\n\n- `Switched` (Booking): transport sync → async; + handler SwitchedHandler (Invoice)", $markdown);
        self::assertStringContainsString("### Test transport only\n\n- `TestsOnly` (Booking): sync → async", $markdown);
        self::assertStringNotContainsString('`Same`', $markdown, 'unchanged messages are not listed');

        // Overview: the new and the removed cross-module links get edges of their own colour, unchanged ones are muted.
        $overview = $this->section($markdown, '## Overview', '### Booking');
        self::assertStringContainsString('Booking -- "Fresh" --> Search', $overview);
        self::assertStringContainsString('Booking -- "Gone" --> Chat', $overview);
        self::assertStringContainsString('Booking -- "Same" --> Chat', $overview);
        self::assertMatchesRegularExpression('/linkStyle [\d,]+ stroke:#1e8e3e/', $overview, 'an added edge is green');
        self::assertMatchesRegularExpression('/linkStyle [\d,]+ stroke:#c5221f/', $overview, 'a removed edge is red');
        self::assertMatchesRegularExpression('/linkStyle [\d,]+ stroke:#bbb/', $overview, 'an unchanged edge is grey');

        // Module diagram: affected modules only; nodes and edges highlighted by status.
        $booking = $this->section($markdown, '### Booking', '## ');
        self::assertStringNotContainsString('### Review', $markdown, 'an untouched module is not drawn');
        self::assertStringContainsString('class App_Message_Booking_Fresh added', $booking);
        self::assertStringContainsString('class App_Message_Booking_Gone removed', $booking);
        self::assertStringContainsString('class App_Message_Booking_Switched changed', $booking);
        self::assertStringContainsString('class App_Message_Booking_Same unchanged', $booking);
        self::assertStringContainsString('class App_Message_Booking_TestsOnly unchanged', $booking, 'a test-only transport change is not highlighted on the diagram');
        self::assertStringContainsString('App_Message_Booking_Switched{{"Switched<br>sync → async"}}', $booking);
        self::assertStringContainsString('class App_Service_Booking_OfferService added', $booking);
        self::assertStringContainsString('class App_MessageHandler_Chat_GoneHandler removed', $booking);
        self::assertStringContainsString('class App_Service_Booking_BookingService unchanged', $booking);
        self::assertStringContainsString('App_Message_Booking_Switched --> App_MessageHandler_Invoice_SwitchedHandler', $booking);
        self::assertStringContainsString('classDef removed', $booking);
        self::assertStringContainsString('%%{init: {"layout": "elk"}}%%', $booking);
    }

    /**
     * @param list<string>          $publishers
     * @param array<string, string> $handlers  class → module
     */
    private function node(string $class, string $module, string $transport = 'async', string $test = 'async', array $publishers = [], array $handlers = []): MessageNode
    {
        $participantModule = fn (string $c) => explode('\\', $c)[2];

        return new MessageNode(
            class: $class,
            module: $module,
            kind: MessageNode::KIND_EVENT,
            transport: $transport,
            transportInTests: $test,
            publishers: array_map(fn (string $c) => new Participant($c, $participantModule($c)), $publishers),
            handlers: array_map(fn (string $c, string $m) => new Participant($c, $m, 'event.bus'), array_keys($handlers), $handlers),
            failureListeners: [],
        );
    }

    private function section(string $markdown, string $from, string $to): string
    {
        $start = strpos($markdown, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($markdown, $to, $start + \strlen($from));

        return substr($markdown, $start, false === $end ? null : $end - $start);
    }
}
