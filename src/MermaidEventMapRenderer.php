<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * The message map as Markdown with Mermaid diagrams (GitLab renders them in merge requests and in the
 * repository).
 *
 * Three views: an overview of the links between modules (cross-module messages only), a detailed map per
 * owning module (publishers → message → handlers) and a table with the transports. The output is
 * deterministic (everything is sorted) so that a CI freshness check can diff the committed file.
 *
 * Diagrams support highlighting ({@see Highlight}): that is how {@see MermaidEventMapDiffRenderer} draws
 * "was + is" — added in green, removed in red, unchanged muted.
 */
final class MermaidEventMapRenderer
{
    public function __construct(
        private readonly Labels $labels = new Labels(),
    ) {}

    /**
     * The ELK layout routes edges and labels better than dagre; GitLab registers it in its Mermaid 11
     * bundle. Where ELK is not registered, Mermaid falls back to dagre by itself (with a console warning).
     */
    private const LAYOUT_DIRECTIVE = '%%{init: {"layout": "elk"}}%%';

    /** Edge colours per highlight status. */
    private const EDGE_STROKE = [
        MessageChange::ADDED => '#1e8e3e',
        MessageChange::REMOVED => '#c5221f',
        MessageChange::CHANGED => '#e8710a',
        MessageChange::UNCHANGED => '#bbb',
    ];

    private const HIGHLIGHT_CLASS_DEFS = [
        '    classDef added fill:#e6f4ea,stroke:#1e8e3e,color:#0b3d1a,stroke-width:2px',
        '    classDef removed fill:#fdecea,stroke:#c5221f,color:#5c1a17,stroke-width:2px,stroke-dasharray: 4 4',
        '    classDef changed fill:#fff4e5,stroke:#e8710a,color:#5a2d00,stroke-width:2px',
        '    classDef unchanged fill:#f4f4f4,stroke:#bbb,color:#888',
    ];

    public function render(EventMap $map): string
    {
        $out = [];
        $out[] = $this->labels->mapTitle;
        $out[] = '';
        $out[] = $this->labels->generatedNote;
        $out[] = '';
        array_push($out, ...$this->labels->intro);
        $out[] = '';
        $out[] = $this->labels->overviewHeading;
        $out[] = '';
        $out[] = $this->labels->overviewNote;
        $out[] = '';
        array_push($out, ...$this->overviewDiagram($map));
        $out[] = '';
        $out[] = $this->labels->byOwnerHeading;
        $out[] = '';
        foreach ($this->byOwner($map) as $module => $messages) {
            $out[] = '### '.$module;
            $out[] = '';
            array_push($out, ...$this->moduleDiagram($module, $messages));
            $out[] = '';
        }
        $out[] = $this->labels->tableHeading;
        $out[] = '';
        array_push($out, ...$this->table($map));
        $out[] = '';
        array_push($out, ...$this->findings($map));

        return implode("\n", $out)."\n";
    }

    /**
     * Overview: the modules and the cross-module messages between them; with highlighting, added and
     * removed links get edges of their own instead of merging with the unchanged ones.
     *
     * @return list<string>
     */
    public function overviewDiagram(EventMap $map, ?Highlight $highlight = null): array
    {
        /** @var array<string, array{from: string, to: string, async: bool, status: string, labels: list<string>}> $edges */
        $edges = [];
        $modules = [];
        foreach ($map->messages as $message) {
            foreach ($message->publishers as $publisher) {
                foreach ($message->handlers as $handler) {
                    if ($publisher->module === $handler->module) {
                        continue;
                    }
                    $status = null === $highlight ? MessageChange::UNCHANGED : $this->linkStatus($highlight, $message, $publisher, $handler);
                    $key = $publisher->module.'|'.$handler->module.'|'.($message->isAsync() ? 'a' : 's').'|'.$status;
                    $edges[$key] ??= ['from' => $publisher->module, 'to' => $handler->module, 'async' => $message->isAsync(), 'status' => $status, 'labels' => []];
                    $edges[$key]['labels'][] = $message->shortName();
                    $modules[$publisher->module] = $modules[$handler->module] = true;
                }
            }
        }
        $moduleOrder = ModuleOrder::topological(
            array_keys($modules),
            array_map(static fn (array $e) => [$e['from'], $e['to']], array_values($edges)),
        );
        $rank = array_flip($moduleOrder);
        $statusRank = array_flip(array_keys(self::EDGE_STROKE));
        uasort($edges, static fn (array $a, array $b) => [$rank[$a['from']], $rank[$a['to']], $a['async'] ? 0 : 1, $statusRank[$a['status']]] <=> [$rank[$b['from']], $rank[$b['to']], $b['async'] ? 0 : 1, $statusRank[$b['status']]]);

        // Top to bottom: the overview stays narrow and reads without horizontal scrolling.
        $lines = $this->flowchart('TB');
        foreach ($moduleOrder as $module) {
            $lines[] = sprintf('    %s["%s"]', $this->id($module), $module);
        }
        $edgeStatuses = [];
        foreach ($edges as $edge) {
            $labels = array_values(array_unique($edge['labels']));
            sort($labels);
            $lines[] = sprintf(
                '    %s %s "%s" %s %s',
                $this->id($edge['from']),
                $edge['async'] ? '--' : '-.',
                implode('<br>', $labels),
                $edge['async'] ? '-->' : '.->',
                $this->id($edge['to']),
            );
            $edgeStatuses[] = $edge['status'];
        }
        if (null !== $highlight) {
            array_push($lines, ...$this->linkStyles($edgeStatuses));
        }
        $lines[] = '```';

        return $lines;
    }

    /**
     * Map of one owning module: publishers → message → handlers (and retries-exhausted listeners).
     *
     * @param list<MessageNode> $messages
     *
     * @return list<string>
     */
    public function moduleDiagram(string $owner, array $messages, ?Highlight $highlight = null): array
    {
        /** @var array<string, array<string, string>> $nodesByModule module → id → label */
        $nodesByModule = [];
        /** @var array<string, string> $edges line → status */
        $edges = [];
        /** @var array<string, true> $classes `class <id> <class>` lines, keyed so a participant is listed once */
        $classes = [];

        foreach ($messages as $message) {
            $messageId = $this->id($message->class);
            $shape = MessageNode::KIND_COMMAND === $message->kind ? '(["%s"])' : '{{"%s"}}';
            $label = $message->shortName();
            if (null !== $highlight && isset($highlight->messageLabels[$message->class])) {
                $label .= '<br>'.$highlight->messageLabels[$message->class];
            }
            $nodesByModule[$owner][$messageId] = sprintf($shape, $label);
            if (null !== $highlight) {
                $classes[sprintf('    class %s %s', $messageId, $highlight->messageStatus($message->class))] = true;
            } else {
                if ([] === $message->handlers) {
                    $classes[sprintf('    class %s unhandled', $messageId)] = true;
                }
                if ([] === $message->publishers) {
                    $classes[sprintf('    class %s unpublished', $messageId)] = true;
                }
            }

            $arrow = $message->isAsync() ? '-->' : '-.->';
            $edgeStatus = fn (Participant $p) => null === $highlight ? MessageChange::UNCHANGED : $highlight->edgeStatus($message->class, $p->class);
            foreach ($message->publishers as $publisher) {
                $nodesByModule[$publisher->module][$this->id($publisher->class)] = sprintf('["%s"]', $publisher->shortName());
                $edges[sprintf('    %s %s %s', $this->id($publisher->class), $arrow, $messageId)] ??= $edgeStatus($publisher);
            }
            foreach ($message->handlers as $handler) {
                $nodesByModule[$handler->module][$this->id($handler->class)] = sprintf('["%s"]', $handler->shortName());
                $edges[sprintf('    %s %s %s', $messageId, $arrow, $this->id($handler->class))] ??= $edgeStatus($handler);
            }
            foreach ($message->failureListeners as $listener) {
                $nodesByModule[$listener->module][$this->id($listener->class)] = sprintf('["%s"]', $listener->shortName());
                $edges[sprintf('    %s -. "%s" .-> %s', $messageId, $this->labels->retriesExhausted, $this->id($listener->class))] ??= $edgeStatus($listener);
            }
            if (null !== $highlight) {
                foreach ([...$message->publishers, ...$message->handlers, ...$message->failureListeners] as $participant) {
                    $classes[sprintf('    class %s %s', $this->id($participant->class), $highlight->participantStatus($participant->class))] = true;
                }
            }
        }
        ksort($nodesByModule);

        $lines = $this->flowchart('LR');
        foreach ($nodesByModule as $module => $nodes) {
            ksort($nodes);
            $lines[] = sprintf('    subgraph %s["%s"]', $this->id('m_'.$module), $module);
            foreach ($nodes as $id => $label) {
                $lines[] = sprintf('        %s%s', $id, $label);
            }
            $lines[] = '    end';
        }
        array_push($lines, ...array_keys($edges));
        if (null !== $highlight) {
            array_push($lines, ...$this->linkStyles(array_values($edges)));
            array_push($lines, ...self::HIGHLIGHT_CLASS_DEFS);
        } else {
            $lines[] = '    classDef unhandled stroke-dasharray: 5 5';
            $lines[] = '    classDef unpublished fill:#eee,stroke:#999,color:#999';
        }
        array_push($lines, ...array_keys($classes));
        $lines[] = '```';

        return $lines;
    }

    /**
     * A cross-module link is added (or removed) when the message itself or the publisher's or handler's participation is.
     */
    private function linkStatus(Highlight $highlight, MessageNode $message, Participant $publisher, Participant $handler): string
    {
        $statuses = [
            $highlight->messageStatus($message->class),
            $highlight->edgeStatus($message->class, $publisher->class),
            $highlight->edgeStatus($message->class, $handler->class),
        ];
        foreach ([MessageChange::REMOVED, MessageChange::ADDED] as $status) {
            if (\in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return MessageChange::UNCHANGED;
    }

    /**
     * One `linkStyle` per status, edges listed by declaration index.
     *
     * @param list<string> $statuses status of every edge in declaration order
     *
     * @return list<string>
     */
    private function linkStyles(array $statuses): array
    {
        $byStatus = [];
        foreach ($statuses as $i => $status) {
            $byStatus[$status][] = $i;
        }
        $lines = [];
        foreach (self::EDGE_STROKE as $status => $stroke) {
            if (isset($byStatus[$status])) {
                $width = MessageChange::UNCHANGED === $status ? '' : ',stroke-width:2px';
                $lines[] = sprintf('    linkStyle %s stroke:%s%s', implode(',', $byStatus[$status]), $stroke, $width);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function table(EventMap $map): array
    {
        $lines = [
            $this->labels->tableHeader,
            '|---|---|---|---|---|---|---|',
        ];
        $messages = $map->messages;
        usort($messages, static fn (MessageNode $a, MessageNode $b) => [$a->module, $a->shortName()] <=> [$b->module, $b->shortName()]);
        foreach ($messages as $message) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | %s |',
                $message->shortName(),
                MessageNode::KIND_COMMAND === $message->kind ? $this->labels->kindCommand : $this->labels->kindEvent,
                $message->module,
                $message->transport,
                $message->transportInTests,
                $this->participantList($message->publishers) ?: $this->labels->none,
                $this->participantList($message->handlers) ?: $this->labels->none,
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function findings(EventMap $map): array
    {
        $lines = [$this->labels->findingsHeading, ''];
        $unhandled = $map->withoutHandlers();
        $unpublished = $map->withoutPublishers();
        if ([] === $unhandled && [] === $unpublished) {
            $lines[] = $this->labels->findingsAllGood;

            return $lines;
        }
        if ([] !== $unhandled) {
            $lines[] = $this->labels->findingsUnhandled;
            $lines[] = '';
            foreach ($unhandled as $message) {
                $lines[] = sprintf('- `%s` (%s)', $message->shortName(), $message->module);
            }
            $lines[] = '';
        }
        if ([] !== $unpublished) {
            $lines[] = $this->labels->findingsUnpublished;
            $lines[] = '';
            foreach ($unpublished as $message) {
                $lines[] = sprintf('- `%s` (%s)', $message->shortName(), $message->module);
            }
        }

        return $lines;
    }

    /**
     * @return array<string, list<MessageNode>>
     */
    public function byOwner(EventMap $map): array
    {
        $byOwner = [];
        foreach ($map->messages as $message) {
            $byOwner[$message->module][] = $message;
        }
        ksort($byOwner);

        return $byOwner;
    }

    /**
     * @param list<Participant> $participants
     */
    private function participantList(array $participants): string
    {
        return implode('<br>', array_map(
            static fn (Participant $p) => sprintf('%s (%s)', $p->shortName(), $p->module),
            $participants,
        ));
    }

    /**
     * @return list<string>
     */
    private function flowchart(string $direction): array
    {
        return ['```mermaid', self::LAYOUT_DIRECTIVE, 'flowchart '.$direction];
    }

    private function id(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }
}
