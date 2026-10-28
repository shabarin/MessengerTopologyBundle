<?php

namespace Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\EventListener\Beta;

use Shabarin\MessengerTopologyBundle\Tests\Fixtures\EventMap\Message\Alpha\ThingHappenedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class ThingFailedListener
{
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (!$event->getEnvelope()->getMessage() instanceof ThingHappenedEvent || $event->willRetry()) {
            return;
        }
    }
}
