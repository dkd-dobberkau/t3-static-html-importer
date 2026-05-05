# Skeleton-Aufbau, Schritt für Schritt

Ziel: Nach jedem Schritt existiert etwas Lauffähiges, das committet werden kann, bevor der nächste Schritt anfängt.

## 1. Composer- und Extension-Skeleton

- `composer.json` mit den Dependencies aus dem Brief
- `ext_emconf.php`
- `Configuration/Services.yaml` mit Autowiring und Autoconfigure
- `Configuration/Commands.php` mit den drei Command-Registrierungen
- `README.md` mit Kurzbeschreibung, Composer-Install-Snippet und Verweis auf `PROJECT_BRIEF.md`

## 2. Domain-Modelle (DTOs, readonly wo möglich)

- `Domain/Model/SourceDocument`
- `Domain/Model/ContentBlock`
- `Domain/Model/ClassificationResult` (Felder: `type`, `confidence`, `rationale`)
- `Domain/Model/ImportMapping`

## 3. Source Adapter

- `Service/Source/SourceAdapterInterface`
- `Service/Source/LocalFilesAdapter` (voll implementiert, liest Verzeichnisse rekursiv)
- `Service/Source/CrawlAdapter` (Stub mit Interface, `@todo` für Symfony BrowserKit)
- `Service/Source/PatternLibraryAdapter` (Stub mit Interface, `@todo` für Storybook/Fractal)

## 4. Structural Analyzer

- `Service/Analyzer/StructuralAnalyzer` auf Basis Symfony DomCrawler
- `Service/Analyzer/BlockHasher` (Struktur-Hash ohne Textinhalte, für Wiederholungserkennung)
- Heuristik-Regeln initial: `<section>`, `<article>`, `data-component`, ARIA-Rollen, BEM-Klassen
- Output: Liste von `ContentBlock`-Objekten mit Typ-Kandidaten und Confidence

## 5. AI-Schicht

- `Service/Ai/AiClassifier` (Constructor-Injection von `B13\Aim\Ai`)
- `Service/Ai/PromptLibrary` (zentrale Prompts, kein Inline-Prompting)
- `Service/Ai/AiClassifierMock` für Tests
- `Service/Ai/ResultCache` mit Disk-Cache unter `var/cache/t3_static_html_importer/`
- AiClassifier liefert drei Methoden:
  - `classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult`
  - `extractFieldValue(string $domFragment, FieldDefinition $field): ?string`
  - `enrichAssetMetadata(string $imagePath): AssetMetadata`

## 6. Mapping-Loader

- `Service/Mapping/YamlMappingLoader`
- `Resources/Private/Mapping/example.yaml` mit kommentiertem Beispiel
- Validation der YAML gegen ein einfaches Schema (Symfony Config oder eigene Validator-Logik)

## 7. Analyze-Command (End-to-End)

- `Command/AnalyzeCommand`
- Argumente: Pfad zur Quelle, optionale Mapping-YAML
- Optionen: `--no-ai` für rein deterministischen Lauf, `--threshold` für Confidence-Schwelle
- Output: Markdown-Report nach stdout oder in Datei
- Soll bereits einen ersten Mapping-Vorschlag generieren (LLM-assistiert), wenn `--no-ai` nicht gesetzt ist

## 8. Tests

- `Tests/Unit/Service/Analyzer/StructuralAnalyzerTest`
- `Tests/Unit/Service/Ai/AiClassifierTest` (mit Mock, kein Netz)
- `Tests/Unit/Service/Mapping/YamlMappingLoaderTest`
- `Tests/Unit/Service/Source/LocalFilesAdapterTest`

## 9. Stubs für die nächste Phase

- `FluidPartialGenerator` (Interface plus `@todo`)
- `FieldTransformer` (Interface plus `@todo`)
- `ContentImporter`, `FalImporter` (Interfaces plus `@todo`)
- `templates`- und `import`-Commands als Stubs, die "not yet implemented" loggen und einen Hinweis auf den geplanten Funktionsumfang geben

## Reihenfolge der Sessions in Claude Code

Empfehlung, jeweils mit eigenem Commit am Ende:

- **Session A:** Schritte 1 und 2. Reines Boilerplate, gehört zusammen.
- **Session B:** Schritt 3. Source Adapter, weil hier die `SourceDocument`-Schnittstelle festgelegt wird.
- **Session C:** Schritte 4 und 5. Analyzer und AiClassifier sind über `ClassificationResult` eng gekoppelt.
- **Session D:** Schritt 6. Mapping-Loader, isoliert testbar.
- **Session E:** Schritt 7. Analyze-Command bringt End-to-End.
- **Session F:** Schritt 8 und 9. Tests grün ziehen, Stubs setzen.

## Hinweis zur AiM-Integration

In Session C zuerst den AiClassifier mit dem Mock vollständig fertigbauen und alle Tests grün bekommen, bevor das echte AiM angesprochen wird. AiM ist Alpha, die API kann sich kurzfristig ändern, und ein Bugfix-Loop in der AiM-Anbindung soll nicht parallel zur Pipeline-Architektur entstehen.
