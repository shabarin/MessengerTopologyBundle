<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Report of the map changes (see {@see EventMapDiff}): a text list first — it reads even where Mermaid
 * does not render — then "was + is" diagrams with highlighting. Not meant to be committed: it is a review
 * report.
 */
final class MermaidEventMapDiffRenderer
{
    public function __construct(
        private readonly MermaidEventMapRenderer $renderer = new MermaidEventMapRenderer(),
        private readonly Labels $labels = new Labels(),
    ) {}

    public function render(EventMapChanges $changes, string $baseLabel): string
    {
        $out = [$this->labels->diffTitle, '', sprintf($this->labels->diffComparedTo, $baseLabel), ''];
        if ($changes->isEmpty()) {
            $out[] = $this->labels->diffNoChanges;

            return implode("\n", $out)."\n";
        }

        array_push($out, ...$this->list($this->labels->diffAdded, $changes->byStatus(MessageChange::ADDED), $this->participantsLine(...)));
        array_push($out, ...$this->list($this->labels->diffRemoved, $changes->byStatus(MessageChange::REMOVED), $this->participantsLine(...)));
        array_push($out, ...$this->list($this->labels->diffChanged, $changes->byStatus(MessageChange::CHANGED), $this->changesLine(...)));
        array_push($out, ...$this->list($this->labels->diffTestsOnly, $changes->byStatus(MessageChange::TESTS_ONLY), static fn (MessageChange $c) => sprintf('%s → %s', ...$c->transportInTests ?? ['', ''])));

        $highlighted = $changes->byStatus(MessageChange::ADDED, MessageChange::REMOVED, MessageChange::CHANGED);
        if ([] === $highlighted) {
            return implode("\n", $out)."\n";
        }

        $union = $changes->union();
        $highlight = $changes->highlight();
        $out[] = $this->labels->diffLegend;
        $out[] = '';
        $out[] = $this->labels->diffOverviewHeading;
        $out[] = '';
        array_push($out, ...$this->renderer->overviewDiagram($union, $highlight));
        $out[] = '';
        $out[] = $this->labels->diffAffectedHeading;
        $out[] = '';
        $affected = array_unique(array_map(static fn (MessageChange $c) => $c->merged->module, $highlighted));
        foreach ($this->renderer->byOwner($union) as $module => $messages) {
            if (!\in_array($module, $affected, true)) {
                continue;
            }
            $out[] = '### '.$module;
            $out[] = '';
            array_push($out, ...$this->renderer->moduleDiagram($module, $messages, $highlight));
            $out[] = '';
        }

        return rtrim(implode("\n", $out))."\n";
    }

    /**
     * @param list<MessageChange>             $changes
     * @param callable(MessageChange): string $details
     *
     * @return list<string>
     */
    private function list(string $title, array $changes, callable $details): array
    {
        if ([] === $changes) {
            return [];
        }
        $lines = [$title, ''];
        foreach ($changes as $change) {
            $detail = $details($change);
            $lines[] = sprintf('- `%s` (%s)%s', $change->shortName(), $change->merged->module, '' === $detail ? '' : ': '.$detail);
        }
        $lines[] = '';

        return $lines;
    }

    private function participantsLine(MessageChange $change): string
    {
        $parts = [];
        if ([] !== $change->merged->publishers) {
            $parts[] = sprintf($this->labels->publishes, $this->names($change->merged->publishers));
        }
        if ([] !== $change->merged->handlers) {
            $parts[] = sprintf($this->labels->handles, $this->names($change->merged->handlers));
        }
        if ([] !== $change->merged->failureListeners) {
            $parts[] = sprintf($this->labels->listensRetries, $this->names($change->merged->failureListeners));
        }

        return implode('; ', $parts);
    }

    private function changesLine(MessageChange $change): string
    {
        $parts = [];
        if (null !== $change->transport) {
            $parts[] = sprintf($this->labels->transportChange, ...$change->transport);
        }
        if (null !== $change->transportInTests) {
            $parts[] = sprintf($this->labels->testTransportChange, ...$change->transportInTests);
        }
        if (null !== $change->module) {
            $parts[] = sprintf($this->labels->moduleChange, ...$change->module);
        }
        if (null !== $change->kind) {
            $parts[] = sprintf($this->labels->kindChange, ...$change->kind);
        }
        foreach ([
            [$this->labels->plusPublisher, $change->addedPublishers],
            [$this->labels->minusPublisher, $change->removedPublishers],
            [$this->labels->plusHandler, $change->addedHandlers],
            [$this->labels->minusHandler, $change->removedHandlers],
            [$this->labels->plusListener, $change->addedListeners],
            [$this->labels->minusListener, $change->removedListeners],
        ] as [$prefix, $participants]) {
            foreach ($participants as $participant) {
                $parts[] = sprintf('%s %s (%s)', $prefix, $participant->shortName(), $participant->module);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @param list<Participant> $participants
     */
    private function names(array $participants): string
    {
        return implode(', ', array_map(static fn (Participant $p) => sprintf('%s (%s)', $p->shortName(), $p->module), $participants));
    }
}
