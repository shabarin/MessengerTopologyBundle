<?php

namespace Shabarin\MessengerTopologyBundle;

/**
 * Code-structure conventions {@see EventMapCollector} relies on to understand a project.
 *
 * A "layer" is the first namespace segment after the root (`App\Message\…` → layer `Message`). A class
 * belongs to the "module" named by the next segment when that segment is a sub-namespace of one of the
 * module layers (`App\Service\Billing\…` → `Billing`); otherwise its module is the layer itself
 * (`App\Pricing\…` → `Pricing`).
 */
final readonly class Conventions
{
    /**
     * @param string       $rootNamespace      namespace prefix of the scanned code (the PSR-4 root)
     * @param list<string> $messageLayers      layers whose concrete classes are all messages
     * @param list<string> $commandLayers      layers whose messages are commands (besides anything handled on the command bus)
     * @param list<string> $moduleLayers       layers whose sub-namespaces name the modules
     * @param string       $commandBus         bus whose handlers make a message a command
     * @param list<string> $nonPublisherLayers layers where `new <Message>` is not a publication (e.g. denormalizers)
     */
    public function __construct(
        public string $rootNamespace = 'App',
        public array $messageLayers = ['Message'],
        public array $commandLayers = ['CommandMessage'],
        public array $moduleLayers = ['Entity', 'Service', 'Message', 'MessageHandler'],
        public string $commandBus = 'command.bus',
        public array $nonPublisherLayers = [],
    ) {}
}
