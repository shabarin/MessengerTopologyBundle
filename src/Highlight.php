<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Diagram highlighting: the status of every node and edge of the merged map (see {@see EventMapChanges::highlight()}).
 * Without it the renderer draws the plain map.
 */
final readonly class Highlight
{
    /**
     * @param array<string, string> $messages      message class → MessageChange::ADDED|REMOVED|CHANGED|UNCHANGED
     * @param array<string, string> $participants  participant class → ADDED|REMOVED|UNCHANGED
     * @param array<string, string> $edges         "message|participant" → ADDED|REMOVED|UNCHANGED
     * @param array<string, string> $messageLabels message class → second line of the node label (e.g. "sync → async")
     */
    public function __construct(
        public array $messages,
        public array $participants,
        public array $edges,
        public array $messageLabels = [],
    ) {}

    public function messageStatus(string $class): string
    {
        return $this->messages[$class] ?? MessageChange::UNCHANGED;
    }

    public function participantStatus(string $class): string
    {
        return $this->participants[$class] ?? MessageChange::UNCHANGED;
    }

    public function edgeStatus(string $message, string $participant): string
    {
        return $this->edges[$message.'|'.$participant] ?? MessageChange::UNCHANGED;
    }
}
