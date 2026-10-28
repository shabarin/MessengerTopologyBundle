<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Structural diff of two maps: by messages, participants and transports, not by Markdown lines.
 * A renamed class shows up as "removed + added" — for Messenger it is a different message anyway.
 */
final class EventMapDiff
{
    public static function between(EventMap $base, EventMap $head): EventMapChanges
    {
        $baseBy = self::byClass($base);
        $headBy = self::byClass($head);
        $classes = array_unique([...array_keys($baseBy), ...array_keys($headBy)]);
        sort($classes);

        $changes = [];
        foreach ($classes as $class) {
            $changes[] = self::compare($baseBy[$class] ?? null, $headBy[$class] ?? null);
        }

        $baseParticipants = self::participantClasses($base);
        $headParticipants = self::participantClasses($head);
        $participants = [];
        foreach (array_diff($headParticipants, $baseParticipants) as $class) {
            $participants[$class] = MessageChange::ADDED;
        }
        foreach (array_diff($baseParticipants, $headParticipants) as $class) {
            $participants[$class] = MessageChange::REMOVED;
        }

        return new EventMapChanges($changes, $participants);
    }

    private static function compare(?MessageNode $base, ?MessageNode $head): MessageChange
    {
        if (null === $base) {
            \assert(null !== $head);

            return new MessageChange($head->class, MessageChange::ADDED, $head, addedPublishers: $head->publishers, addedHandlers: $head->handlers, addedListeners: $head->failureListeners);
        }
        if (null === $head) {
            return new MessageChange($base->class, MessageChange::REMOVED, $base, removedPublishers: $base->publishers, removedHandlers: $base->handlers, removedListeners: $base->failureListeners);
        }

        [$addedPublishers, $removedPublishers] = self::participantDiff($base->publishers, $head->publishers);
        [$addedHandlers, $removedHandlers] = self::participantDiff($base->handlers, $head->handlers);
        [$addedListeners, $removedListeners] = self::participantDiff($base->failureListeners, $head->failureListeners);
        $pair = static fn (string $a, string $b): ?array => $a === $b ? null : [$a, $b];
        $transport = $pair($base->transport, $head->transport);
        $transportInTests = $pair($base->transportInTests, $head->transportInTests);
        $module = $pair($base->module, $head->module);
        $kind = $pair($base->kind, $head->kind);

        $changed = [] !== $addedPublishers || [] !== $removedPublishers
            || [] !== $addedHandlers || [] !== $removedHandlers
            || [] !== $addedListeners || [] !== $removedListeners
            || null !== $transport || null !== $module || null !== $kind;
        $status = $changed ? MessageChange::CHANGED : (null !== $transportInTests ? MessageChange::TESTS_ONLY : MessageChange::UNCHANGED);

        $merged = new MessageNode(
            class: $head->class,
            module: $head->module,
            kind: $head->kind,
            transport: $head->transport,
            transportInTests: $head->transportInTests,
            publishers: [...$head->publishers, ...$removedPublishers],
            handlers: [...$head->handlers, ...$removedHandlers],
            failureListeners: [...$head->failureListeners, ...$removedListeners],
        );

        return new MessageChange(
            $head->class,
            $status,
            $merged,
            $addedPublishers,
            $removedPublishers,
            $addedHandlers,
            $removedHandlers,
            $addedListeners,
            $removedListeners,
            $transport,
            $transportInTests,
            $module,
            $kind,
        );
    }

    /**
     * @param list<Participant> $base
     * @param list<Participant> $head
     *
     * @return array{0: list<Participant>, 1: list<Participant>} [added, removed]
     */
    private static function participantDiff(array $base, array $head): array
    {
        $classesOf = static fn (array $list) => array_map(static fn (Participant $p) => $p->class, $list);
        $baseClasses = $classesOf($base);
        $headClasses = $classesOf($head);

        return [
            array_values(array_filter($head, static fn (Participant $p) => !\in_array($p->class, $baseClasses, true))),
            array_values(array_filter($base, static fn (Participant $p) => !\in_array($p->class, $headClasses, true))),
        ];
    }

    /**
     * @return array<string, MessageNode>
     */
    private static function byClass(EventMap $map): array
    {
        $by = [];
        foreach ($map->messages as $message) {
            $by[$message->class] = $message;
        }

        return $by;
    }

    /**
     * @return list<string>
     */
    private static function participantClasses(EventMap $map): array
    {
        $classes = [];
        foreach ($map->messages as $message) {
            foreach ([...$message->publishers, ...$message->handlers, ...$message->failureListeners] as $p) {
                $classes[$p->class] = true;
            }
        }

        return array_keys($classes);
    }
}
