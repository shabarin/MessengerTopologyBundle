<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessage\Beta;

final class DoThing
{
    public function __construct(public readonly int $thingId) {}
}
