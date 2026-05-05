# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/dkd-dobberkau/t3-static-html-importer/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dkd-dobberkau/t3-static-html-importer/releases/tag/v0.1.0
