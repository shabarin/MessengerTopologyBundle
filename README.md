# MessengerTopologyBundle

A map of Symfony Messenger messages generated from the code: who publishes (`new <Message>`), who handles
(`#[AsMessageHandler]`), which transport carries each message (`messenger.yaml`). No container boot and no
running application: sources are parsed with `PhpToken`, attributes are read through reflection.

- `bin/console messenger-topology:dump` — Markdown with Mermaid diagrams: an overview of the links between modules,
  one diagram per owning module, a table, and findings (messages without a handler or without a publisher).
- `bin/console messenger-topology:dump --format=json` — a machine-readable snapshot of the same map.
- `bin/console messenger-topology:diff --base=<file|-> --base-label="main (abc123)"` — a structural diff of the
  current code against a snapshot: a text list (added / removed / changed / test transport only) and
  "was + is" diagrams where added is green, removed stays as a red dashed ghost, a transport change is amber
  and everything unchanged is muted.

Diagrams ask for the ELK layout (`%%{init: {"layout": "elk"}}%%`); GitLab registers it in its Mermaid 11
bundle, and without it Mermaid falls back to dagre.

## Installation

```bash
composer require --dev shabarin/messenger-topology-bundle
```

Until the package is on Packagist, add the repository first:

```json
"repositories": [{"type": "vcs", "url": "https://github.com/shabarin/MessengerTopologyBundle"}]
```

## Setup

```php
// config/bundles.php
Shabarin\MessengerTopologyBundle\MessengerTopologyBundle::class => ['dev' => true, 'test' => true],
```

```yaml
# config/packages/messenger_topology.yaml
# The bundle is registered in dev and test only, so the configuration must be scoped the same way.
when@dev: &topology
    messenger_topology:
        root_namespace: App
        message_layers: [Message, DomainEvent, CommandMessage]
        command_layers: [CommandMessage]
        module_layers: [Entity, Service, Message, MessageHandler]
        command_bus: command.bus
        non_publisher_layers: [Serializer]
when@test: *topology
```

Every option is documented in `bin/console config:dump-reference messenger_topology`. In short: a "layer"
is the first namespace segment after `root_namespace` (`App\Message\…` → `Message`); `message_layers` are
the layers whose classes are all messages; `module_layers` are the layers whose sub-namespaces name the
modules (`App\Service\Billing\…` → `Billing`), any other class belongs to the module named after its layer
(`App\Pricing\…` → `Pricing`); `non_publisher_layers` are the layers where constructing a message is not a
publication (denormalizers).

Report texts are English; pass your own `Labels` to the renderers to change them.

## Snapshot and diff in CI

The scripts in `bin/` target GitLab CI and are configured through environment variables:

- `bin/report-topology-changes.sh` takes the snapshot from the merge base with `TARGET_BRANCH`, runs
  `messenger-topology:diff` and writes `events-diff.md`. Variables: `MESSENGER_TOPOLOGY_CONSOLE` (how to run the console,
  default `bin/console`), `MESSENGER_TOPOLOGY_SNAPSHOT` (path of the committed snapshot, default
  `docs/diagrams/events.json`), `MESSENGER_TOPOLOGY_REPORT` (output file).
- `bin/post-topology-comment.sh` posts the report as a merge request comment, one per MR, updated in
  place through a hidden marker. Needs `MESSENGER_TOPOLOGY_COMMENT_TOKEN` (scope `api`, not a protected variable).

## Tests

```bash
composer install && vendor/bin/phpunit
```
