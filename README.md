# Static HTML Importer

TYPO3 extension that imports static HTML into Fluid templates, `tt_content` records and FAL assets, with optional AI-assisted block classification via [`b13/aim`](https://packagist.org/packages/b13/aim).

Status: skeleton phase. See [`PROJECT_BRIEF.md`](PROJECT_BRIEF.md) for goals and architecture, [`TASKS.md`](TASKS.md) for the build plan, and the [Skeleton Phase milestone](https://github.com/dkd-dobberkau/t3-static-html-importer/milestone/1) for open work.

## Requirements

- PHP `^8.2`
- TYPO3 `^13.4 || ^14.0`
- `b13/aim ^0.1` (alpha, wrapped behind an internal adapter)

## Installation

```bash
composer require t3x/static-html-importer
```

Activate the extension afterwards:

```bash
vendor/bin/typo3 extension:setup static_html_importer
```

## CLI commands

Three Symfony Console commands, run in order:

| Command | Purpose |
| --- | --- |
| `t3:static-html:analyze` | Read sources, emit a structure report and a first mapping suggestion. |
| `t3:static-html:templates` | Generate Fluid partials, layouts and templates from analyzed sources. |
| `t3:static-html:import` | Persist `tt_content` records and FAL assets. |

Run analyze first, review the mapping, run templates, review again, then import. The order is intentional.

## Architecture

Five stages, see [`PROJECT_BRIEF.md`](PROJECT_BRIEF.md):

1. Source Adapter
2. Structural Analyzer
3. Template Extractor
4. Content Mapper
5. Asset Importer

Determinism first, AI as fallback. All LLM calls go through `B13\Aim\Ai`, wrapped by the local `AiClassifier`.

## License

GPL-2.0-or-later.
