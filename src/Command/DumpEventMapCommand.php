<?php

namespace Shabarin\MessengerTopologyBundle\Command;

use Shabarin\MessengerTopologyBundle\EventMapCollector;
use Shabarin\MessengerTopologyBundle\EventMapJson;
use Shabarin\MessengerTopologyBundle\MermaidEventMapRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Messenger message map (publishers, handlers, transports) as Markdown + Mermaid or as JSON.
 * Both are meant to be committed (e.g. docs/diagrams/events.{md,json}) and checked against the code in CI;
 * the JSON is the snapshot `messenger-topology:diff` compares with.
 */
#[AsCommand(
    name: 'messenger-topology:dump',
    description: 'Print the Messenger message map (publishers, handlers, transports) as Markdown with Mermaid diagrams or as JSON.',
)]
final class DumpEventMapCommand extends Command
{
    public function __construct(
        private readonly EventMapCollector $collector,
        private readonly MermaidEventMapRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'markdown or json', 'markdown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $map = $this->collector->collect();
        $output->write(match ($input->getOption('format')) {
            'markdown' => $this->renderer->render($map),
            'json' => EventMapJson::encode($map),
            default => throw new \InvalidArgumentException('--format must be markdown or json'),
        });

        return Command::SUCCESS;
    }
}
