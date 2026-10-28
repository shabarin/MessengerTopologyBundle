<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * A class taking part in the message exchange: a publisher, a handler or a failure listener.
 */
final readonly class Participant
{
    public function __construct(
        public string $class,
        public string $module,
        /** Handler bus (event.bus / command.bus); null for publishers and listeners. */
        public ?string $bus = null,
    ) {}

    public function shortName(): string
    {
        return substr($this->class, (int) strrpos($this->class, '\\') + 1);
    }
}
