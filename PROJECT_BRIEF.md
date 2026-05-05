# T3 Static HTML Importer – Project Brief

## Zweck

TYPO3-Extension, die statisches HTML aus heterogenen Quellen (lokale Verzeichnisse, gecrawlte Sites, Pattern Libraries) einliest und daraus Fluid-Templates, tt_content-Records und FAL-Assets erzeugt. Eine AI-Komponente unterstützt Block-Klassifikation, Field-Extraction und Asset-Metadaten und wird ausschließlich über `b13/aim` als zentrale TYPO3-AI-Schicht angesprochen.

## Composer-Identität

- name: `dkd/t3-static-html-importer`
- type: `typo3-cms-extension`
- license: `GPL-2.0-or-later`
- vendor namespace: `Dkd\T3StaticHtmlImporter`
- composer command namespace: `t3:static-html:*`

## Ziel-Versionen

- PHP `^8.2`
- TYPO3 `^13.4 || ^14.0`
- `b13/aim ^0.1` (Alpha, hinter eigenem Adapter kapseln, weil API vor 1.0 noch wechseln kann)

## Architektur, fünf Stufen

1. **Source Adapter.** Heterogene Quellen werden auf ein einheitliches `SourceDocument` abgebildet.
2. **Structural Analyzer.** DOM wird in semantische `ContentBlock`-Objekte segmentiert.
3. **Template Extractor.** Wiederkehrende Strukturen werden zu Fluid-Partials destilliert.
4. **Content Mapper.** `ContentBlock` wird auf tt_content gemappt, YAML-getrieben.
5. **Asset Importer.** Bilder und Medien gehen in den FAL, dedupliziert per SHA1.

## Drei CLI-Commands (Symfony Console)

- `t3:static-html:analyze` liest Quellen, gibt einen Struktur-Report aus, optional bereits ein Mapping-Vorschlag (Dry-Run).
- `t3:static-html:templates` generiert Fluid-Partials in `Resources/Private/Templates/`, `Layouts/`, `Partials/`.
- `t3:static-html:import` persistiert Records und FAL-Assets.

Die Reihenfolge ist beabsichtigt: erst analysieren und Mapping justieren, dann Templates rausschreiben und reviewen, dann erst Daten in die DB.

## AI-Strategie

- Deterministisches Parsing zuerst, LLM nur als Fallback bei niedriger Confidence.
- Alle LLM-Calls über `B13\Aim\Ai`, kein direkter Provider-Zugriff.
- Eigener `AiClassifier` kapselt AiM, damit AiM-API-Brüche begrenzt bleiben.
- Modellwahl überlässt der Importer dem AiM-Routing, fordert aber Text- und Vision-Capability.
- Jeder LLM-Call wird gecacht (Hash über Input, Disk-Cache unter `var/cache/t3_static_html_importer/`).
- Confidence-Schwelle konfigurierbar; darunter Eskalation in einen Review-Report (CSV oder Markdown).
- Modell-Strategie pragmatisch: Haiku für hochvolumige Klassifikation, Sonnet für strukturelle und generative Aufgaben, Opus nur für anspruchsvolles Reasoning.

## Mapping-DSL

YAML pro CType, geladen aus `Resources/Private/Mapping/*.yaml`. Beispiel siehe `Resources/Private/Mapping/example.yaml`.

## Konventionen

- Keine em dashes, keine Pfeil-Symbole in Doc-Comments und Reports.
- Plain Text oder Markdown als Default-Output, keine Word-Dokumente.
- Tests mit phpunit, mindestens Unit-Tests für Analyzer, AiClassifier, YamlMappingLoader.
- AiClassifier muss mockbar sein, kein Netz in Tests.
- Prompts zentral in `PromptLibrary`, nicht inline im Code.
- Strukturierte LLM-Outputs erzwingen (JSON mit fixem Schema), keine offenen Aufträge.

## Aktueller Status

Skeleton-Phase. Funktionsfähig im ersten Wurf:

- `LocalFilesAdapter`
- `StructuralAnalyzer` mit deterministischer Heuristik
- `AiClassifier` als AiM-Wrapper plus Mock
- `YamlMappingLoader`
- `analyze`-Command End-to-End

Stubs mit Interfaces und `@todo`:

- `CrawlAdapter`, `PatternLibraryAdapter`
- `FluidPartialGenerator`
- `FieldTransformer`, `ContentImporter`, `FalImporter`
- `templates`- und `import`-Commands
