# Examples

This directory contains fixtures you can run the importer against right out of the box, plus example mapping YAMLs.

```
examples/
├── sample-site/         small static HTML site (3 pages)
├── sample-patterns/     Fractal-style component pattern library
└── mappings/            example mapping files (one per cType)
```

The standalone runner [`bin/t3shi`](../bin/t3shi) wires `analyze` and `templates` without needing TYPO3 to boot. The full `import` command needs a real TYPO3 site (DataHandler, FAL); see the [main README](../README.md) for that path.

## Quick start

After `composer install --no-plugins --no-scripts`:

```bash
# Heuristic-only analysis of the sample site
bin/t3shi analyze examples/sample-site --no-ai

# Deterministic + AI mock (demonstrates how AI escalation looks)
bin/t3shi analyze examples/sample-site

# Pattern library mode
bin/t3shi analyze "pattern-library:examples/sample-patterns" --no-ai

# Crawl a public site (replace with a real URL)
bin/t3shi analyze https://example.com/ --no-ai
```

Add `--output=/abs/path/to/report.md --force` to write the markdown report to a file.

Add `--review=/abs/path/to/review.md --force` to additionally write a low-confidence review report.

## Generating templates

```bash
mkdir -p /tmp/t3shi-out
bin/t3shi templates examples/sample-site \
    --target=/tmp/t3shi-out \
    --no-ai \
    --dry-run

# Without --dry-run, the same command writes:
#   /tmp/t3shi-out/Layouts/Default.html
#   /tmp/t3shi-out/Partials/Generated/<hash>.html  (one per unique block structure)
#   /tmp/t3shi-out/Templates/<cType>.html          (one per detected cType)
#   /tmp/t3shi-out/Partials/Generated/.manifest.json
```

Re-running the command without changes is idempotent: it reports "All targets already up to date." and skips writes.

## Source modes at a glance

| Mode | Source string | Adapter |
| --- | --- | --- |
| Local files | `/abs/path/or/relative/dir` | `LocalFilesAdapter` |
| HTTP crawl | `http://example.com/` or `https://example.com/` | `CrawlAdapter` (BFS, depth 2 / 100 pages by default, same-origin only) |
| Pattern library | `pattern-library:/path/to/lib` | `PatternLibraryAdapter` (Fractal-style `*/index.html`) |

## Mapping YAML

Mappings sit one file per cType. The schema is small:

```yaml
cType: textmedia            # required
selector: 'section.card'    # optional CSS-style hint
fields:                      # optional, one entry per tt_content column
  header:
    description: 'The block heading'
    type: string             # one of: string, html, int, date, image
  bodytext:
    description: 'The body text'
    type: html
  image:
    description: 'The hero image'
    type: image              # routed through FalImporter, sys_file_reference is created
```

[`mappings/hero.yaml`](mappings/hero.yaml) and [`mappings/textmedia.yaml`](mappings/textmedia.yaml) are working examples.

When `bin/t3shi analyze` is given a mapping, it lists matched cTypes in the report header. The full `t3:static-html:import` command (TYPO3 only) consumes mappings to build tt_content payloads.

## What the runner cannot do

`bin/t3shi` deliberately does not expose `t3:static-html:import`:

- DataHandler needs `$GLOBALS['TYPO3_CONF_VARS']`, a backend user, and the configured connection.
- FAL needs a TYPO3 storage definition and the file index tables.

Both are only sensible inside a booted TYPO3 site. Inside one, use:

```bash
vendor/bin/typo3 t3:static-html:import \
    examples/sample-site \
    examples/mappings \
    --target-pid=42 \
    --dry-run
```

## Test it without writing any code

```bash
git clone https://github.com/dkd-dobberkau/t3-static-html-importer.git
cd t3-static-html-importer
composer install --no-plugins --no-scripts
vendor/bin/phpunit                    # 113 unit tests
bin/t3shi analyze examples/sample-site --no-ai
```

The full unit-test suite is also what CI runs on every push, on PHP 8.2, 8.3 and 8.4.
