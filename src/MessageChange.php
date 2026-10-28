<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * What happened to one message between the base and the current map.
 */
final readonly class MessageChange
{
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const CHANGED = 'changed';
    /** Only the when@test transport changed: no effect on prod, not highlighted on the diagrams. */
    public const TESTS_ONLY = 'tests-only';
    public const UNCHANGED = 'unchanged';

    /**
     * @param list<Participant>          $addedPublishers
     * @param list<Participant>          $removedPublishers
     * @param list<Participant>          $addedHandlers
     * @param list<Participant>          $removedHandlers
     * @param list<Participant>          $addedListeners
     * @param list<Participant>          $removedListeners
     * @param array{0: string, 1: string}|null $transport        [was, is]
     * @param array{0: string, 1: string}|null $transportInTests
     * @param array{0: string, 1: string}|null $module
     * @param array{0: string, 1: string}|null $kind
     */
    public function __construct(
        public string $class,
        public string $status,
        /** Node for the merged diagram: properties from head (from base for a removed message), participants merged. */
        public MessageNode $merged,
        public array $addedPublishers = [],
        public array $removedPublishers = [],
        public array $addedHandlers = [],
        public array $removedHandlers = [],
        public array $addedListeners = [],
        public array $removedListeners = [],
        public ?array $transport = null,
        public ?array $transportInTests = null,
        public ?array $module = null,
        public ?array $kind = null,
    ) {}

    public function shortName(): string
    {
        return $this->merged->shortName();
    }

    /** Whether the participant's involvement in this message is added / removed. */
    public function participantEdgeStatus(Participant $participant): string
    {
        if (self::ADDED === $this->status) {
            return self::ADDED;
        }
        if (self::REMOVED === $this->status) {
            return self::REMOVED;
        }
        foreach ([...$this->addedPublishers, ...$this->addedHandlers, ...$this->addedListeners] as $p) {
            if ($p->class === $participant->class) {
                return self::ADDED;
            }
        }
        foreach ([...$this->removedPublishers, ...$this->removedHandlers, ...$this->removedListeners] as $p) {
            if ($p->class === $participant->class) {
                return self::REMOVED;
            }
        }

        return self::UNCHANGED;
    }
}
