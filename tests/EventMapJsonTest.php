<?php

namespace Shabarin\MessengerTopologyBundle\Tests;

use Shabarin\MessengerTopologyBundle\Conventions;
use Shabarin\MessengerTopologyBundle\EventMapCollector;
use Shabarin\MessengerTopologyBundle\EventMapJson;
use PHPUnit\Framework\TestCase;

class EventMapJsonTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/EventMap';

    public function testRoundTripKeepsTheMapAndIsDeterministic(): void
    {
        $map = (new EventMapCollector(self::FIXTURES, self::FIXTURES.'/messenger.yaml', new Conventions(
            rootNamespace: 'Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap',
            messageLayers: ['Message', 'CommandMessage'],
            moduleLayers: ['Service', 'Message', 'MessageHandler', 'CommandMessage', 'CommandMessageHandler'],
        )))->collect();

        $json = EventMapJson::encode($map);

        self::assertEquals($map, EventMapJson::decode($json));
        self::assertSame($json, EventMapJson::encode(EventMapJson::decode($json)));
        self::assertStringEndsWith("\n", $json, 'the file is committed, so it ends with a newline');
        self::assertStringContainsString('"class": "Shabarin\\\\MessengerTopologyBundle\\\\Tests\\\\Fixtures\\\\EventMap\\\\Message\\\\Alpha\\\\ThingHappenedEvent"', $json, 'readable pretty-print; nothing but backslashes is escaped');
    }
}
