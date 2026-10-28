<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * The message map: output of {@see EventMapCollector}, input of {@see MermaidEventMapRenderer}.
 */
final readonly class EventMap
{
    public function __construct(
        /** @var list<MessageNode> sorted by FQCN */
        public array $messages,
    ) {}

    /** @return list<MessageNode> */
    public function withoutHandlers(): array
    {
        return array_values(array_filter($this->messages, fn (MessageNode $m) => [] === $m->handlers));
    }

    /** @return list<MessageNode> */
    public function withoutPublishers(): array
    {
        return array_values(array_filter($this->messages, fn (MessageNode $m) => [] === $m->publishers));
    }

    /** @return list<string> modules appearing as owners, publishers or handlers */
    public function modules(): array
    {
        $modules = [];
        foreach ($this->messages as $message) {
            $modules[$message->module] = true;
            foreach ([...$message->publishers, ...$message->handlers, ...$message->failureListeners] as $participant) {
                $modules[$participant->module] = true;
            }
        }
        $modules = array_keys($modules);
        sort($modules);

        return $modules;
    }
}
