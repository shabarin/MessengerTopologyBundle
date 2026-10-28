<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessageHandler\Beta;

use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\CommandMessage\Beta\DoThing;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DoThingHandler
{
    #[AsMessageHandler(bus: 'command.bus')]
    public function handle(DoThing $command): void
    {
    }
}
