<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * One Messenger message with everything the code and messenger.yaml say about it.
 */
final readonly class MessageNode
{
    public const KIND_EVENT = 'event';
    public const KIND_COMMAND = 'command';

    public function __construct(
        public string $class,
        /** Owning module, derived from the message class namespace. */
        public string $module,
        /** self::KIND_* */
        public string $kind,
        /** Transport from the prod routing in messenger.yaml; `sync` when the message is not routed. */
        public string $transport,
        /** Transport under when@test; `sync` when not routed there either. */
        public string $transportInTests,
        /** @var list<Participant> classes doing `new <Message>` */
        public array $publishers,
        /** @var list<Participant> #[AsMessageHandler] handlers */
        public array $handlers,
        /** @var list<Participant> WorkerMessageFailedEvent listeners doing `instanceof <Message>` */
        public array $failureListeners,
    ) {}

    public function shortName(): string
    {
        return substr($this->class, (int) strrpos($this->class, '\\') + 1);
    }

    public function isAsync(): bool
    {
        return 'sync' !== $this->transport;
    }
}
