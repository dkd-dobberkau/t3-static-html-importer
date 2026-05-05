# Contributing

Thanks for considering a contribution. This project is in skeleton phase, so the architecture is still moving; please open an issue or comment on an existing one before starting non-trivial work, so we can align on scope.

## Where to start

- [`PROJECT_BRIEF.md`](PROJECT_BRIEF.md) explains the goals and the five-stage architecture.
- [`TASKS.md`](TASKS.md) lists the original skeleton breakdown.
- The [issue tracker](https://github.com/dkd-dobberkau/t3-static-html-importer/issues) groups work by milestone. Issues labeled `good first issue` are isolated and well-scoped; `help wanted` calls out places where outside expertise speeds things up.

## Local setup

```bash
git clone https://github.com/dkd-dobberkau/t3-static-html-importer.git
cd t3-static-html-importer
composer install --no-scripts
vendor/bin/phpunit                         # unit suite
vendor/bin/phpunit -c Build/FunctionalTests.xml   # functional suite (sqlite)
```

`--no-scripts` skips composer.json scripts. The `typo3/cms-composer-installers` plugin still runs and lays out a minimal `public/` tree; that layout is required by `typo3/testing-framework` for functional tests.

The functional suite needs `pdo_sqlite` (PHP extension) and writes a transient instance under `var/tests/`. Both directories are gitignored.

PHP `^8.2` and the dependencies in [`composer.json`](composer.json) are required.

## Coding standards

- PHP 8.2+, `declare(strict_types=1)` in every file.
- DTOs are `final readonly`. Confidence values are validated to `[0.0, 1.0]` in their constructors.
- All LLM prompts go through [`PromptLibrary`](Classes/Service/Ai/PromptLibrary.php). No inline prompts.
- All AI calls go through `AiClassifierInterface` so tests can swap in `AiClassifierMock`. Tests do not hit the network.
- No em dashes in doc comments or report output. Plain text or markdown only.
- Tests live under `Tests/Unit/...` and use the `\T3x\StaticHtmlImporter\Tests\Unit\...` namespace.
- Functional tests live under `Tests/Functional/...` and use `\T3x\StaticHtmlImporter\Tests\Functional\...`. They run a real (sqlite) TYPO3 instance via `typo3/testing-framework`.

## Pull requests

- Fork, branch, push.
- Keep changes focused — one issue per PR is ideal.
- Run `vendor/bin/phpunit` and `php -l Classes/...` before pushing. CI will run them again on PHP 8.2, 8.3, 8.4.
- Reference the issue you're closing in the PR description. CI must be green before review.

## Reporting issues

When opening a bug, include the smallest HTML snippet that reproduces it, the command you ran, and the actual versus expected output. For AI-related quirks, mention whether you ran with `--no-ai` or with the mock.

## License

By contributing you agree that your contributions are licensed under [GPL-2.0-or-later](LICENSE).
