# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Functional test suite under `Tests/Functional/` driven by
  `typo3/testing-framework` ^9.5 against sqlite. First case
  (`DataHandlerAdapterTest`) exercises insert / find-by-block-id /
  re-run-updates against a real TYPO3 instance, replacing the
  unit-test fakes for the production wrapper. New phpunit config in
  `Build/FunctionalTests.xml`. CI now runs both unit and functional
  suites on PHP 8.2/8.3/8.4. Closes #22.

### Changed

- `composer install` no longer needs `--no-plugins`. The
  `typo3/cms-composer-installers` plugin runs cleanly with
  `--no-scripts` and lays out the minimal `public/` tree required by
  the functional suite. CONTRIBUTING.md updated.

## [0.2.0] - 2026-05-05

### Added

- `SourceAdapterRegistry` routes commands to the right
  `SourceAdapterInterface` based on the source string: `http(s)://`
  -> `CrawlAdapter`, `pattern-library:<path>` -> `PatternLibraryAdapter`,
  everything else -> `LocalFilesAdapter`. AnalyzeCommand,
  TemplatesCommand and ImportCommand now inject the registry
  instead of `LocalFilesAdapter` directly. Reports show which
  adapter ran. 7 unit tests for routing.
- `CrawlAdapter` is functional (HTTP BFS via Symfony HttpClient,
  10 unit tests via MockHttpClient).
- `PatternLibraryAdapter` is functional (Fractal-style component
  walks, 8 unit tests).

### Changed

- `ImportCommand` now creates proper `sys_file_reference` rows for
  `image`-type fields instead of writing the raw FAL uid into the
  tt_content column. The DataHandler transaction now contains the
  tt_content row plus a `sys_file_reference` per imported file
  (with NEW keys), so the resulting record renders correctly in the
  TYPO3 backend with proper FAL relations. Closes the gap between
  "skeleton happy path" and "produces clickable images in the
  backend".
- `ImportCommand` now supports multi-cType mapping directories: it
  loads every YAML in a given directory, then per block picks the
  first mapping whose key matches an entry in
  `block->candidateTypes`. Blocks without a matching mapping are
  reported in the review CSV (`phase: mapping-lookup`) and counted
  in the summary, so partial coverage is visible at a glance. Same
  command also still accepts a single mapping file.
- `DataHandlerAdapterInterface::processContent()` gains a
  `array $fileReferences = []` parameter (defaults to empty so
  existing callers don't break). The production
  `DataHandlerAdapter` builds the multi-table DataHandler payload.

### Added

- `t3:static-html:import` is now functional. Pipeline:
  LocalFilesAdapter → StructuralAnalyzer → ContentImporter
  buildPayload → image-field resolution under the source dir →
  FalImporter for `image`-type fields → DataHandlerAdapter for
  tt_content. Required `--target-pid`, configurable `--storage`
  and `--folder`, `--dry-run` validates without DB or FAL writes,
  `--no-ai` disables FieldTransformer's AI fallback, `--review`
  writes a CSV (document, block_id, phase, message) of validation
  failures. Image paths must resolve under the source root; URLs
  and out-of-tree paths are rejected. The CSV writer reuses the
  AnalyzeCommand path-validation hardening (absolute, no `..`,
  parent must exist). 6 unit tests via fakes.
- `image` field type recognised by `YamlMappingLoader` and
  handled by `FieldTransformer` (returns `<img src>`); used by
  `ImportCommand` to feed FalImporter.
- `t3:static-html:templates` is now functional. Reads HTML sources,
  runs the structural analyzer, optionally calls AiClassifier on
  blocks below `--threshold` (off with `--no-ai`), and hands the
  result to FluidPartialGenerator. `--target` overrides the default
  output (extension's `Resources/Private`); `--dry-run` prints a
  list of planned writes without touching the filesystem. Markdown
  summary is written to stdout. 5 unit tests via CommandTester.
- `FluidPartialGeneratorInterface::generate()` gains an optional
  `bool $dryRun = false` parameter; in dry-run mode the manifest
  is not written and no files are produced, but the return list
  reflects what would have been written.
- `FalImporter` is now functional. Hashes the source file's contents
  and short-circuits to the existing sys_file uid when SHA1 already
  matches (no copy, no re-upload). Otherwise routes through the
  `FalAdapter` (a thin TYPO3 wrapper) to add the file to the
  configured storage and folder. Target syntax accepts FAL
  identifiers `1:/path/to` or bare paths that fall back to a
  configurable default storage uid. When the `enrichMetadata` flag
  is set, populates `sys_file_metadata` via
  `AiClassifier::enrichAssetMetadata`; failures are best-effort and
  do not abort the import. A `sourceBaseDir` constructor argument
  constrains source paths against `<img src="/etc/passwd">`-style
  abuse. 9 unit tests via a recording fake adapter.
- `ContentImporter` is now functional. Builds a tt_content payload
  by routing each `FieldDefinition` through `FieldTransformer`,
  then writes via `DataHandlerAdapter` (a thin abstraction over
  TYPO3's `DataHandler`). Idempotency: column
  `tx_static_html_importer_block_id` carries the BlockHasher hash;
  re-runs update the existing record by uid instead of inserting
  duplicates. `buildPayload()` is public so a future ImportCommand
  dry-run path can preview without DB writes. 6 unit tests via a
  recording fake adapter.
- `Configuration/TCA/Overrides/tt_content.php` registers the
  dedupe column (read-only in the backend).
- `ext_tables.sql` declares the column (varchar(40), indexed).
- `Configuration/Services.yaml` aliases all interfaces to their
  production implementations: FieldTransformer, ContentImporter,
  DataHandlerAdapter, FluidPartialGenerator, FalImporter.
- `FieldTransformer` is now functional: type-aware coercion for
  `string`, `html`, `int`, `date`. Field selectors are guessed from
  `FieldDefinition::$name` (h1-h3 for "header", `<p>` for "bodytext",
  `<img>` for "image", `<a>` for "link", etc.) plus generic
  `.{name}` and `[data-field="{name}"]` fallbacks. `<time datetime>`
  attributes are preferred for date fields. AI fallback via
  `AiClassifierInterface::extractFieldValue` kicks in only when the
  deterministic path returns null; AI failures degrade to null,
  never bubble. 14 unit tests cover each type, the AI fallback, and
  the empty-block / always-failing-AI edges. RteHtmlParser
  integration for `html` type is deferred to TYPO3 wiring.
- `FluidPartialGenerator` is now functional: groups blocks by their
  structural hash (one partial per unique structure), writes a
  per-cType template that composes the partials, and emits a default
  layout if none exists. A manifest under
  `Partials/Generated/.manifest.json` records each generated file's
  content hash so re-runs are idempotent. Hand-edits to generated
  files trigger a regeneration on the next run; persistent
  customisations belong outside `Generated/`. cType labels with
  non-alphanumeric chars (e.g. `role:contentinfo`) are sanitised
  for filename safety. 9 unit tests covering grouping, idempotency,
  external-edit detection, layout-not-overwriting, and cType
  sanitisation.

### Security

- `AnalyzeCommand --output`/`--review`: paths must be absolute, must
  not contain `..`, and the parent directory must already exist.
  Existing files require `--force` to overwrite. Fixes the arbitrary
  file-write primitive a malicious HTML producer could chain with the
  source-read path.
- `LocalFilesAdapter` now skips symlinks and double-checks
  `realpath()` containment, so a `.html` symlink pointing at
  `/etc/passwd` is no longer read and shipped into the LLM prompt.
  File size is capped (10 MB default, configurable per instance).
- `ResultCache` rejects empty / null-byte / `..` cacheDir input,
  resolves relative paths against the working dir, creates parent
  dirs `0700` and writes files `0600`. The cache may contain LLM
  output echoing back input HTML, so it is no longer
  group-readable by default.
- `StructuralAnalyzer::keepLeafMatches()` is now a single-pass
  O(n*depth) ancestor scan instead of O(n^2) nested isAncestor.
  `BlockHasher` caps recursion depth (32) and per-node child count
  (500) so pathological inputs cannot exhaust memory.
- `AiClassifier::enrichAssetMetadata` requires an explicit
  `imageBaseDir` at construction time and refuses any path outside
  it. Image size is capped (20 MB default). Closes the
  arbitrary-file-read-to-LLM-provider primitive.
- `AnalyzeCommand` markdown report: pipe `|`, backtick `` ` ``, and
  embedded newlines in user-influenced strings (paths, types,
  rationale) are escaped so a crafted rationale cannot break the
  table layout in the review report.

### Added

- `.github/dependabot.yml` with weekly Composer + GitHub Actions
  updates, grouped for symfony and phpunit packages.
- Two new unit tests covering symlink rejection and size-cap
  behaviour in `LocalFilesAdapter`.

### Changed

- `AiClassifier` now wires `B13\Aim\Ai`: text generation for
  `classifyBlock` and `extractFieldValue`, vision for
  `enrichAssetMetadata`. The `dispatch()` TODO is closed.

### Added

- `JsonResponseExtractor` strips markdown code fences and surrounding
  prose from LLM responses, then enforces the schema's `required`
  keys. 7 unit tests covering plain JSON, fenced JSON, prose-wrapped
  JSON, missing-required, no-required-array, non-JSON content.
- `LICENSE` (GPLv2 text), `CHANGELOG.md`, `CONTRIBUTING.md`.
- New milestone "Templates & Import" with seven planning issues
  covering FluidPartialGenerator, FieldTransformer, ContentImporter,
  FalImporter, plus the two command wirings.

## [0.1.0] - 2026-05-05

Skeleton phase. The pipeline reads HTML, segments into ContentBlocks,
optionally consults an AI classifier, and emits a markdown report.
Templates and import are stubs. AiClassifier dispatch to b13/aim is a TODO.

### Added

- `composer.json` and `ext_emconf.php` for `t3x/static-html-importer`,
  PHP `^8.2`, TYPO3 `^13.4 || ^14.0`, `b13/aim ^0.1`.
- Domain DTOs: `SourceDocument`, `ContentBlock`, `ClassificationResult`,
  `ImportMapping`, `FieldDefinition`, `AssetMetadata` (all readonly).
- Source adapters: `LocalFilesAdapter` (full), `CrawlAdapter` and
  `PatternLibraryAdapter` (interface + throwing stubs).
- `StructuralAnalyzer` with deterministic heuristics (semantic tags,
  `data-component`, `role`, BEM classes) and `BlockHasher` for
  structure-only repetition detection.
- AI layer: `AiClassifierInterface`, production `AiClassifier`
  (b13/aim wrapper with TODO dispatch), `AiClassifierMock` for tests,
  `PromptLibrary` with strict JSON schemas, `ResultCache` (sha1-keyed
  sharded disk cache).
- `YamlMappingLoader` with Symfony Config schema validation, plus a
  commented `Resources/Private/Mapping/example.yaml`.
- `AnalyzeCommand` end-to-end: source read, structural analysis,
  optional AI escalation under `--threshold`, markdown report,
  optional review report (`--review`), suggested-mappings YAML stubs.
- `TemplatesCommand` and `ImportCommand` skeleton stubs that log the
  planned scope; full implementations follow.
- Next-phase interface/stub pairs: `FluidPartialGenerator`,
  `FieldTransformer`, `ContentImporter`, `FalImporter`.
- 27 unit tests / 50 assertions covering analyzer, AI mock, mapping
  loader, local files adapter. No network in tests.
- GitHub Actions CI on PHP 8.2, 8.3, 8.4 (composer validate, install,
  lint, phpunit, audit).

[Unreleased]: https://github.com/dkd-dobberkau/t3-static-html-importer/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/dkd-dobberkau/t3-static-html-importer/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/dkd-dobberkau/t3-static-html-importer/releases/tag/v0.1.0
