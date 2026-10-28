<?php

namespace Shabarin\MessengerTopologyBundle;

use Shabarin\MessengerTopologyBundle\Command\DiffEventMapCommand;
use Shabarin\MessengerTopologyBundle\Command\DumpEventMapCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the `messenger-topology:dump` and `messenger-topology:diff` commands. The `messenger_topology` configuration
 * tells the collector where the code is and how it is structured ({@see Conventions}); run
 * `bin/console config:dump-reference messenger_topology` for the annotated reference.
 */
final class MessengerTopologyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $defaults = new Conventions();
        $definition->rootNode()
            ->children()
                ->scalarNode('src_dir')
                    ->info('Directory scanned for PHP classes: messages, publishers (`new <Message>`), handlers (#[AsMessageHandler]) and retries-exhausted listeners (WorkerMessageFailedEvent + instanceof).')
                    ->defaultValue('%kernel.project_dir%/src')
                ->end()
                ->scalarNode('messenger_config')
                    ->info('messenger.yaml whose framework.messenger.routing (and the when@test override) gives each message its transport; a message without a route is reported as "sync".')
                    ->defaultValue('%kernel.project_dir%/config/packages/messenger.yaml')
                ->end()
                ->scalarNode('root_namespace')
                    ->info('Namespace prefix of the scanned code (the PSR-4 root, e.g. "App"). Layers and modules are read from the namespace segments that follow it.')
                    ->defaultValue($defaults->rootNamespace)
                ->end()
                ->arrayNode('message_layers')
                    ->info('Layers (first namespace segment after the root: "Message" for App\Message\...) whose concrete classes are all messages. A class elsewhere counts as a message only when it has a handler or a route.')
                    ->scalarPrototype()->end()
                    ->defaultValue($defaults->messageLayers)
                ->end()
                ->arrayNode('command_layers')
                    ->info('Layers whose messages are commands (drawn as stadiums) rather than events (hexagons). A message handled on the command bus is a command whatever its layer.')
                    ->scalarPrototype()->end()
                    ->defaultValue($defaults->commandLayers)
                ->end()
                ->arrayNode('module_layers')
                    ->info('Layers whose sub-namespaces name the modules: with "Service" listed, App\Service\Billing\... belongs to the module "Billing". A class outside these layers belongs to the module named after its layer (App\Pricing\... -> "Pricing").')
                    ->scalarPrototype()->end()
                    ->defaultValue($defaults->moduleLayers)
                ->end()
                ->scalarNode('command_bus')
                    ->info('Name of the Messenger bus whose handlers make a message a command.')
                    ->defaultValue($defaults->commandBus)
                ->end()
                ->arrayNode('non_publisher_layers')
                    ->info('Layers where `new <Message>` is not a publication, e.g. "Serializer": a denormalizer builds the message when it arrives from the transport, it does not emit it.')
                    ->scalarPrototype()->end()
                    ->defaultValue($defaults->nonPublisherLayers)
                ->end()
            ->end();
    }

    /**
     * @param array{src_dir: string, messenger_config: string, root_namespace: string, message_layers: list<string>, command_layers: list<string>, module_layers: list<string>, command_bus: string, non_publisher_layers: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set(Conventions::class)->args([
            '$rootNamespace' => $config['root_namespace'],
            '$messageLayers' => $config['message_layers'],
            '$commandLayers' => $config['command_layers'],
            '$moduleLayers' => $config['module_layers'],
            '$commandBus' => $config['command_bus'],
            '$nonPublisherLayers' => $config['non_publisher_layers'],
        ]);
        $services->set(Labels::class);
        $services->set(EventMapCollector::class)->args([
            '$srcDir' => $config['src_dir'],
            '$messengerConfigFile' => $config['messenger_config'],
            '$conventions' => service(Conventions::class),
        ]);
        $services->set(MermaidEventMapRenderer::class)->args([service(Labels::class)]);
        $services->set(MermaidEventMapDiffRenderer::class)->args([service(MermaidEventMapRenderer::class), service(Labels::class)]);
        $services->set(DumpEventMapCommand::class)
            ->args([service(EventMapCollector::class), service(MermaidEventMapRenderer::class)])
            ->tag('console.command');
        $services->set(DiffEventMapCommand::class)
            ->args([service(EventMapCollector::class), service(MermaidEventMapDiffRenderer::class)])
            ->tag('console.command');
    }
}
