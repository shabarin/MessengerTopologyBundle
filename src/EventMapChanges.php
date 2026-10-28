<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Result of {@see EventMapDiff}: the changes per message plus the merged map for the "was + is" diagrams.
 */
final readonly class EventMapChanges
{
    /**
     * @param list<MessageChange>   $changes      sorted by class
     * @param array<string, string> $participants participant class → MessageChange::ADDED|REMOVED|UNCHANGED
     */
    public function __construct(
        public array $changes,
        private array $participants,
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->byStatus(MessageChange::ADDED, MessageChange::REMOVED, MessageChange::CHANGED, MessageChange::TESTS_ONLY);
    }

    public function change(string $class): MessageChange
    {
        foreach ($this->changes as $change) {
            if ($change->class === $class) {
                return $change;
            }
        }
        throw new \OutOfBoundsException(sprintf('No change recorded for %s', $class));
    }

    /** @return list<MessageChange> */
    public function byStatus(string ...$statuses): array
    {
        return array_values(array_filter($this->changes, static fn (MessageChange $c) => \in_array($c->status, $statuses, true)));
    }

    public function union(): EventMap
    {
        return new EventMap(array_map(static fn (MessageChange $c) => $c->merged, $this->changes));
    }

    public function participantStatus(string $class): string
    {
        return $this->participants[$class] ?? MessageChange::UNCHANGED;
    }

    /** Highlighting for {@see MermaidEventMapRenderer}: the status of every node and edge of the merged map. */
    public function highlight(): Highlight
    {
        $messages = [];
        $edges = [];
        $labels = [];
        foreach ($this->changes as $change) {
            $messages[$change->class] = \in_array($change->status, [MessageChange::ADDED, MessageChange::REMOVED, MessageChange::CHANGED], true)
                ? $change->status
                : MessageChange::UNCHANGED;
            if (null !== $change->transport) {
                $labels[$change->class] = sprintf('%s → %s', ...$change->transport);
            }
            foreach ([...$change->merged->publishers, ...$change->merged->handlers, ...$change->merged->failureListeners] as $participant) {
                $edges[$change->class.'|'.$participant->class] = $change->participantEdgeStatus($participant);
            }
        }

        return new Highlight($messages, $this->participants, $edges, $labels);
    }
}
