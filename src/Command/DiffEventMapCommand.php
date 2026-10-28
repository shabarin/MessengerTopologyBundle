<?php

namespace Shabarin\MessengerTopologyBundle\Command;

use Shabarin\MessengerTopologyBundle\EventMapCollector;
use Shabarin\MessengerTopologyBundle\EventMapDiff;
use Shabarin\MessengerTopologyBundle\EventMapJson;
use Shabarin\MessengerTopologyBundle\MermaidEventMapDiffRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What changed in the message map compared to a base snapshot (JSON from `messenger-topology:dump --format=json`
 * on another branch): a text list plus highlighted "was + is" diagrams. The base snapshot comes from a file
 * or from stdin (`--base=-`), so it can be piped straight from `git show <ref>:path`.
 */
#[AsCommand(
    name: 'messenger-topology:diff',
    description: 'Compare the current Messenger message map with a base JSON snapshot and print the changes as Markdown with highlighted Mermaid diagrams.',
)]
final class DiffEventMapCommand extends Command
{
    public function __construct(
        private readonly EventMapCollector $collector,
        private readonly MermaidEventMapDiffRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Path to the base snapshot JSON, or "-" for stdin')
            ->addOption('base-label', null, InputOption::VALUE_REQUIRED, 'How to name the base in the report, e.g. "main (abc123)"', 'base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $input->getOption('base');
        if (!\is_string($base) || '' === $base) {
            throw new \InvalidArgumentException('--base is required: a path to the snapshot JSON or "-" for stdin');
        }
        $json = '-' === $base ? stream_get_contents(\STDIN) : file_get_contents($base);
        if (false === $json || '' === trim($json)) {
            throw new \RuntimeException(sprintf('Base snapshot is empty or unreadable: %s', $base));
        }

        $changes = EventMapDiff::between(EventMapJson::decode($json), $this->collector->collect());
        $output->write($this->renderer->render($changes, (string) $input->getOption('base-label')));

        return Command::SUCCESS;
    }
}
