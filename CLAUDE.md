# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer install
vendor/bin/phpunit                                   # whole suite
vendor/bin/phpunit --filter testCollectsPublishers   # single test
vendor/bin/phpunit tests/EventMapDiffTest.php        # single file
```

`phpunit.xml.dist` sets `failOnDeprecation` and `failOnWarning`, so a deprecation is a test failure. CI (`.github/workflows/tests.yml`) runs the suite on PHP 8.2, 8.3 and 8.4 — don't use syntax newer than 8.2.

The bundle has no console of its own; the `messenger-topology:*` commands only run inside a host Symfony app (it was extracted from the killerbnb backend).

## Architecture

A static map of Symfony Messenger messages, built without booting the container or running the app.

Pipeline: `EventMapCollector` → `EventMap` (list of `MessageNode`) → either `MermaidEventMapRenderer` (Markdown + Mermaid, `dump`) / `EventMapJson` (snapshot, `dump --format=json`), or `EventMapDiff::between(base, head)` → `EventMapChanges` → `MermaidEventMapDiffRenderer` (`diff`).

- **Collector** (`EventMapCollector`): tokenizes every PHP file under `src_dir` with `PhpToken` (deliberately no `nikic/php-parser` dependency) to get the class name, `use` map, and the targets of `new` and `instanceof`; then uses reflection for `#[AsMessageHandler]`. Consequence: scanned classes must be autoloadable (`class_exists` is called) — test fixtures live under the `Tests\` PSR-4 namespace for that reason.
  - A *publisher* is any class doing `new <Message>` — construction, not dispatch, because dispatch is often indirect. Classes in `non_publisher_layers` (denormalizers) and in message layers are excluded.
  - A *failure listener* is a class that mentions `WorkerMessageFailedEvent` and does `instanceof <Message>`.
  - Transports come from `framework.messenger.routing` and the `when@test` override in `messenger.yaml`; unrouted = `sync`.
  - A message is a *command* if handled on `command_bus` or in a `command_layers` layer; otherwise an *event*.
- **Layers and modules** (`Conventions`, configured in `MessengerTopologyBundle::configure()`): a layer is the first namespace segment after `root_namespace`; modules are discovered as sub-directories of `module_layers`, and a class's module is the first such segment in its namespace, else its layer.
- **Why the diff works on a JSON snapshot**: the collector relies on reflection, so it can only see the current checkout. The base side is therefore a committed JSON snapshot (read from a file or stdin via `--base=-`), never a second tree. `bin/report-topology-changes.sh` pipes `git show <merge-base>:<snapshot>` into `diff`.
- **Diff semantics** (`EventMapDiff`): structural, keyed by message class — a renamed class is removed + added. A change only in the test transport gets its own `TESTS_ONLY` status. The "was + is" diagrams render the *merged* map (head plus removed participants as ghosts) through the same `MermaidEventMapRenderer` with a `Highlight`.
- **Rendering**: output must stay deterministic (everything sorted) because host apps commit the Markdown and check it for freshness in CI. Overview module order comes from `ModuleOrder::topological` (flow order, not alphabetical, to reduce back-edges). Diagrams request the ELK layout; all user-facing text lives in `Labels`.

## CI scripts (`bin/`)

POSIX `sh` scripts for the *host app's GitLab CI*, configured by `MESSENGER_TOPOLOGY_*` env vars (see README). `report-topology-changes.sh` is informational and must never fail the pipeline; `post-topology-comment.sh` keeps one MR comment updated in place via a hidden marker.
