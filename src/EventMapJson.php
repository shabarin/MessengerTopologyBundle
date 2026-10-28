<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Machine-readable snapshot of the map, committed next to the Markdown (e.g. docs/diagrams/events.json) so
 * that {@see EventMapDiff} can compare the current code with a base branch without rebuilding the map from
 * another tree (the collector relies on reflection and only sees the classes of the current checkout).
 */
final class EventMapJson
{
    public static function encode(EventMap $map): string
    {
        $messages = array_map(static fn (MessageNode $m) => [
            'class' => $m->class,
            'module' => $m->module,
            'kind' => $m->kind,
            'transport' => $m->transport,
            'transportInTests' => $m->transportInTests,
            'publishers' => array_map(self::participant(...), $m->publishers),
            'handlers' => array_map(self::participant(...), $m->handlers),
            'failureListeners' => array_map(self::participant(...), $m->failureListeners),
        ], $map->messages);

        return json_encode(['messages' => $messages], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    public static function decode(string $json): EventMap
    {
        /** @var array{messages: list<array<string, mixed>>} $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $participants = static fn (array $list) => array_map(
            static fn (array $p) => new Participant($p['class'], $p['module'], $p['bus'] ?? null),
            $list,
        );

        return new EventMap(array_map(static fn (array $m) => new MessageNode(
            class: $m['class'],
            module: $m['module'],
            kind: $m['kind'],
            transport: $m['transport'],
            transportInTests: $m['transportInTests'],
            publishers: $participants($m['publishers']),
            handlers: $participants($m['handlers']),
            failureListeners: $participants($m['failureListeners']),
        ), $data['messages']));
    }

    /**
     * @return array<string, string|null>
     */
    private static function participant(Participant $p): array
    {
        return ['class' => $p->class, 'module' => $p->module, 'bus' => $p->bus];
    }
}
