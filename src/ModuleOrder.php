<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Order of the modules in the overview diagram: along the message flow (publisher before receiver)
 * rather than alphabetical. Mermaid's dagre and ELK rank nodes in declaration order, so this order
 * yields fewer backward arrows and crossings.
 */
final class ModuleOrder
{
    /**
     * Kahn's algorithm with alphabetical tie-breaking. Cycles (Billing ↔ Booking) are broken
     * deterministically: when no node is ready, the module with the fewest remaining incoming edges
     * goes next, the first alphabetically on a tie.
     *
     * @param list<string>                     $modules
     * @param list<array{0: string, 1: string}> $edges   [from, to] pairs; self-loops and duplicates are ignored
     *
     * @return list<string>
     */
    public static function topological(array $modules, array $edges): array
    {
        $remaining = array_values(array_unique($modules));
        sort($remaining);

        /** @var array<string, array<string, true>> $outgoing from → set of to */
        $outgoing = array_fill_keys($remaining, []);
        /** @var array<string, int> $inDegree */
        $inDegree = array_fill_keys($remaining, 0);
        foreach ($edges as [$from, $to]) {
            if ($from === $to || !isset($outgoing[$from], $inDegree[$to]) || isset($outgoing[$from][$to])) {
                continue;
            }
            $outgoing[$from][$to] = true;
            ++$inDegree[$to];
        }

        $order = [];
        while ([] !== $remaining) {
            $ready = array_values(array_filter($remaining, static fn (string $m) => 0 === $inDegree[$m]));
            if ([] === $ready) {
                $minDegree = min(array_map(static fn (string $m) => $inDegree[$m], $remaining));
                $ready = array_values(array_filter($remaining, static fn (string $m) => $inDegree[$m] === $minDegree));
            }
            $next = $ready[0];
            $order[] = $next;
            $remaining = array_values(array_diff($remaining, [$next]));
            foreach (array_keys($outgoing[$next]) as $to) {
                --$inDegree[$to];
            }
        }

        return $order;
    }
}
