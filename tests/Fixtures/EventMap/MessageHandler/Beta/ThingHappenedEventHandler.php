<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\MessageHandler\Beta;

use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha\ThingHappenedEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final class ThingHappenedEventHandler
{
    public function __invoke(ThingHappenedEvent $event): void
    {
    }
}
