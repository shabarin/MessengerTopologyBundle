<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha;

final class ThingHappenedEvent
{
    public function __construct(public readonly int $thingId) {}
}
