<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Service\Alpha;

use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessage\Beta\DoThing;
use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha\{ThingHappenedEvent as Happened};

final class AlphaService
{
    /** @return list<object> */
    public function act(int $thingId): array
    {
        return [new Happened($thingId), new DoThing($thingId), new \ArrayObject()];
    }
}
