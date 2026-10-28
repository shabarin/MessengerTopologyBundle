<?php

namespace Shabarin\MessengerTopologyBundle;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects the Messenger message map from the code, without the container and without running the app.
 *
 * Sources:
 *  - messages: concrete classes in the message layers, plus anything that has a handler or a route
 *    in messenger.yaml;
 *  - publishers: any `new <Message>(...)` in the scanned directory — a message is constructed only to be
 *    sent, while the dispatch itself is often indirect (a `dispatchEvent()` helper), so construction is
 *    what we look for;
 *  - handlers: #[AsMessageHandler] on a class or a method (`handles` / the first parameter type);
 *  - failure listeners: a class mentioning WorkerMessageFailedEvent and doing `instanceof <Message>`
 *    (compensation once the retries are exhausted);
 *  - transport: framework.messenger.routing (prod) and when@test (tests); no route means sync.
 *
 * The module of a class is the second namespace segment when it is a known module (App\Service\Billing\… →
 * Billing), otherwise the first one (App\Pricing\… → Pricing, App\Messenger\… → Messenger). Known modules
 * are the sub-namespaces of the module layers from {@see Conventions}, which also names the message and
 * command layers and the command bus.
 *
 * Files are parsed with PhpToken: no dependency on nikic/php-parser.
 */
final class EventMapCollector
{
    /** @var list<string> */
    private array $modules = [];

    public function __construct(
        private readonly string $srcDir,
        private readonly ?string $messengerConfigFile,
        private readonly Conventions $conventions = new Conventions(),
    ) {}

    public function collect(): EventMap
    {
        $this->modules = $this->discoverModules();
        [$routing, $routingInTests, $defaultBus] = $this->readRouting();

        /** @var array<string, array{news: list<string>, instanceofs: list<string>, failureListener: bool}> $scanned */
        $scanned = [];
        /** @var array<string, list<Participant>> $handlers message class → handlers */
        $handlers = [];
        /** @var array<string, true> $commandBusHandled */
        $commandBusHandled = [];

        foreach ($this->phpFiles() as $file) {
            $parsed = $this->parseFile($file);
            if (null === $parsed) {
                continue;
            }
            $class = $parsed['class'];
            if (!class_exists($class)) {
                continue;
            }
            $scanned[$class] = $parsed;

            foreach ($this->handledMessages(new \ReflectionClass($class)) as [$handled, $bus]) {
                $bus ??= $defaultBus;
                $handlers[$handled][] = new Participant($class, $this->moduleOf($class), $bus);
                if ($this->conventions->commandBus === $bus) {
                    $commandBusHandled[$handled] = true;
                }
            }
        }

        $messageClasses = array_unique([
            ...array_keys($handlers),
            ...array_keys($routing),
            ...array_keys($routingInTests),
            ...array_filter(array_keys($scanned), fn (string $class) => $this->isInMessageLayer($class) && $this->isConcrete($class)),
        ]);
        sort($messageClasses);

        $messages = [];
        foreach ($messageClasses as $messageClass) {
            $publishers = [];
            $failureListeners = [];
            foreach ($scanned as $class => $parsed) {
                if ($class === $messageClass || $this->isInMessageLayer($class) || $this->isInLayer($class, $this->conventions->nonPublisherLayers)) {
                    continue;
                }
                if (\in_array($messageClass, $parsed['news'], true)) {
                    $publishers[] = new Participant($class, $this->moduleOf($class));
                }
                if ($parsed['failureListener'] && \in_array($messageClass, $parsed['instanceofs'], true)) {
                    $failureListeners[] = new Participant($class, $this->moduleOf($class));
                }
            }

            $isCommand = isset($commandBusHandled[$messageClass])
                || $this->isInLayer($messageClass, $this->conventions->commandLayers);

            $messages[] = new MessageNode(
                class: $messageClass,
                module: $this->moduleOf($messageClass),
                kind: $isCommand ? MessageNode::KIND_COMMAND : MessageNode::KIND_EVENT,
                transport: $routing[$messageClass] ?? 'sync',
                transportInTests: $routingInTests[$messageClass] ?? $routing[$messageClass] ?? 'sync',
                publishers: $this->sorted($publishers),
                handlers: $this->sorted($handlers[$messageClass] ?? []),
                failureListeners: $this->sorted($failureListeners),
            );
        }

        return new EventMap($messages);
    }

    /**
     * @return list<array{0: string, 1: ?string}> [message class, bus]
     */
    private function handledMessages(\ReflectionClass $reflection): array
    {
        $handled = [];
        foreach ($reflection->getAttributes(AsMessageHandler::class) as $attribute) {
            $config = $attribute->newInstance();
            $message = $config->handles ?? $this->firstParameterType($reflection, $config->method ?? '__invoke');
            if (null !== $message) {
                $handled[] = [$message, $config->bus];
            }
        }
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                $config = $attribute->newInstance();
                $message = $config->handles ?? $this->firstParameterType($reflection, $method->getName());
                if (null !== $message) {
                    $handled[] = [$message, $config->bus];
                }
            }
        }

        return $handled;
    }

    private function firstParameterType(\ReflectionClass $reflection, string $method): ?string
    {
        if (!$reflection->hasMethod($method)) {
            return null;
        }
        $parameters = $reflection->getMethod($method)->getParameters();
        if ([] === $parameters) {
            return null;
        }
        $type = $parameters[0]->getType();

        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }

    /**
     * Namespace, use map, the first declared class, and the targets of `new` and `instanceof`.
     *
     * @return array{class: string, news: list<string>, instanceofs: list<string>, failureListener: bool}|null
     */
    private function parseFile(string $file): ?array
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents($file));
        $namespace = '';
        /** @var array<string, string> $uses alias → FQCN */
        $uses = [];
        $class = null;
        $news = [];
        $instanceofs = [];
        $mentionsWorkerFailed = false;

        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->is(T_NAMESPACE)) {
                $name = $this->nextNameToken($tokens, $i);
                $namespace = $name?->text ?? '';
                continue;
            }
            if ($token->is(T_USE) && null === $class) {
                $this->parseUse($tokens, $i, $uses);
                continue;
            }
            if (null === $class && $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $previous = $this->previousSignificant($tokens, $i);
                if ($previous?->is(T_DOUBLE_COLON) || $previous?->is(T_NEW)) {
                    continue; // Foo::class / anonymous class
                }
                $name = $this->nextNameToken($tokens, $i);
                if (null !== $name) {
                    $class = ('' === $namespace ? '' : $namespace.'\\').$name->text;
                }
                continue;
            }
            if ($token->is(T_NEW)) {
                $name = $this->nextNameToken($tokens, $i);
                if (null !== $name && !\in_array(strtolower($name->text), ['static', 'self', 'parent'], true)) {
                    $news[] = $this->resolveName($name->text, $namespace, $uses);
                }
                continue;
            }
            if ($token->is(T_INSTANCEOF)) {
                $name = $this->nextNameToken($tokens, $i);
                if (null !== $name) {
                    $instanceofs[] = $this->resolveName($name->text, $namespace, $uses);
                }
                continue;
            }
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && WorkerMessageFailedEvent::class === $this->resolveName($token->text, $namespace, $uses)) {
                $mentionsWorkerFailed = true;
            }
        }

        if (null === $class) {
            return null;
        }

        return [
            'class' => $class,
            'news' => array_values(array_unique($news)),
            'instanceofs' => array_values(array_unique($instanceofs)),
            'failureListener' => $mentionsWorkerFailed,
        ];
    }

    /**
     * `use A\B;`, `use A\B as C;`, `use A\{B, C as D};` — function/const imports are skipped.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $uses
     */
    private function parseUse(array $tokens, int $i, array &$uses): void
    {
        $count = \count($tokens);
        $prefix = '';
        $current = '';
        $alias = null;
        $inGroup = false;
        $isFunctionOrConst = false;

        for ($j = $i + 1; $j < $count; ++$j) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->is([T_FUNCTION, T_CONST])) {
                $isFunctionOrConst = true;
                continue;
            }
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if (null !== $alias) {
                    $alias = $token->text;
                } else {
                    $current = ltrim($token->text, '\\');
                }
                continue;
            }
            if ($token->is(T_NS_SEPARATOR)) {
                continue;
            }
            if ($token->is(T_AS)) {
                $alias = '';
                continue;
            }
            if ('{' === $token->text) {
                $inGroup = true;
                $prefix = rtrim($current, '\\').'\\';
                $current = '';
                continue;
            }
            if (',' === $token->text || '}' === $token->text || ';' === $token->text) {
                if ('' !== $current && !$isFunctionOrConst) {
                    $fqcn = ($inGroup ? $prefix : '').$current;
                    $uses[$alias ?: substr($fqcn, (int) strrpos($fqcn, '\\') + 1)] = $fqcn;
                }
                $current = '';
                $alias = null;
                if (';' === $token->text) {
                    return;
                }
            }
        }
    }

    /**
     * @param array<string, string> $uses
     */
    private function resolveName(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with($name, 'namespace\\')) {
            return ('' === $namespace ? '' : $namespace.'\\').substr($name, \strlen('namespace\\'));
        }
        $segments = explode('\\', $name);
        if (isset($uses[$segments[0]])) {
            $segments[0] = $uses[$segments[0]];

            return implode('\\', $segments);
        }

        return ('' === $namespace ? '' : $namespace.'\\').$name;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function nextNameToken(array $tokens, int $i): ?\PhpToken
    {
        $count = \count($tokens);
        for ($j = $i + 1; $j < $count; ++$j) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE]) ? $token : null;
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function previousSignificant(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: string} [prod routing, test routing, default bus]
     */
    private function readRouting(): array
    {
        if (null === $this->messengerConfigFile) {
            return [[], [], 'messenger.bus.default'];
        }
        $config = Yaml::parseFile($this->messengerConfigFile);
        $messenger = $config['framework']['messenger'] ?? [];
        $normalize = static fn (array $routing): array => array_map(
            static fn ($transport) => \is_array($transport) ? implode(', ', $transport) : (string) $transport,
            $routing,
        );

        return [
            $normalize($messenger['routing'] ?? []),
            $normalize($config['when@test']['framework']['messenger']['routing'] ?? []),
            $messenger['default_bus'] ?? 'messenger.bus.default',
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverModules(): array
    {
        $modules = [];
        foreach ($this->conventions->moduleLayers as $layer) {
            $dir = $this->srcDir.'/'.$layer;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ('.' !== $entry[0] && is_dir($dir.'/'.$entry)) {
                    $modules[$entry] = true;
                }
            }
        }

        return array_keys($modules);
    }

    /**
     * The first namespace segment (excluding the class name) that is a known module: App\Service\Billing\paypal\X →
     * Billing, App\Serializer\Normalizer\Profile\X → Profile. Otherwise the layer: App\Pricing\X → Pricing, App\Messenger\X → Messenger.
     */
    private function moduleOf(string $class): string
    {
        $segments = explode('\\', $this->relativeNamespace($class));
        foreach (\array_slice($segments, 0, -1) as $segment) {
            if (\in_array($segment, $this->modules, true)) {
                return $segment;
            }
        }

        return $segments[0];
    }

    private function relativeNamespace(string $class): string
    {
        $prefix = $this->conventions->rootNamespace.'\\';

        return str_starts_with($class, $prefix) ? substr($class, \strlen($prefix)) : $class;
    }

    private function isInMessageLayer(string $class): bool
    {
        return $this->isInLayer($class, $this->conventions->messageLayers);
    }

    /**
     * @param list<string> $layers
     */
    private function isInLayer(string $class, array $layers): bool
    {
        $first = explode('\\', $this->relativeNamespace($class))[0];

        return \in_array($first, $layers, true);
    }

    private function isConcrete(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return !$reflection->isInterface() && !$reflection->isAbstract() && !$reflection->isEnum();
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<Participant>
     */
    private function sorted(array $participants): array
    {
        usort($participants, static fn (Participant $a, Participant $b) => strcmp($a->class, $b->class));

        return array_values($participants);
    }
}
