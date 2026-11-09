# Working on this repository

Part of Supertext's translation plugins project: Supertext AI translation for the top open source CMS, PIM and shop systems. Each system has its own repo named `Supertext/<System>-Supertext-Translation`. This one is the **PrestaShop** module (PHP, module `supertext` in `supertext/`).

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs. New screens or messages that mention the key get the links too.

## UI languages (always)

The plugin's own UI (buttons, panels, dialogs, settings, permissions, messages) is available in English, German, French and Italian through the CMS's own translation mechanism, so it follows the user's back-end language. New or changed strings get all four languages in the same commit. Formal address (Sie, vous, Lei), the CMS's own terms in each language, "Supertext", placeholders and URLs never translated.

## Plugin list (always)

`README.md` ends with the shared list of all Supertext plugins (between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers). It is identical in every Supertext plugin repo: when a plugin is added, renamed or its description changes, update the list in **all** repos, not just this one.

## Releases (always)

Releases are published by `.github/workflows/release.yml`: never tag or create a GitHub release by hand. To release, follow `docs/DEVELOPER.md` → *Releasing* (new version section in `CHANGELOG.md`, same number in `supertext/supertext.php`) and push to `main`. A push without a new version releases nothing. The settings page shows the version from `supertext.php` (never a second copy) and links it to the release.

## Repo setup (always)

Every Supertext plugin repo has, and a new one gets from the start:

- `LICENSE` matching the license its manifest declares (`composer.json`, `package.json`, `pyproject.toml`, `.csproj`, plugin header).
- `SECURITY.md`: report vulnerabilities privately through GitHub's private vulnerability reporting or support@supertext.com, never in public issues.
- `.github/dependabot.yml`: weekly updates for its package ecosystem and GitHub Actions, minor and patch updates grouped into one pull request.
- `.github/workflows/checks.yml` (actionlint + zizmor on every push and PR, dependency review on PRs) and `.github/workflows/links.yml` (lychee weekly and on docs changes; broken links open the issue "Broken links in the docs"). Third-party actions are pinned to commit SHAs.
- PHP repos: PHPStan in CI (`phpstan.neon`, baseline in `phpstan-baseline.neon`).
- On GitHub: the About box filled in (one-sentence description, website https://www.supertext.com, topics), `main` protected against force-pushes and deletion, Wiki and Projects off, Dependabot alerts and private vulnerability reporting on, secret scanning with push protection and CodeQL default setup on, and the Supertext social preview image.
- A row in the plugin list (see *Plugin list*) and in the org profile (`Supertext/.github` → `profile/README.md`).

Claude sessions can't change GitHub repo settings (HTTP 403): add a new repo to Remy's setup script (`set-github-about`) instead of trying.

## Checks and alerts (always)

Before starting work in a repo, look at its open findings and fix what the task touches or what is quick: code scanning alerts (`gh api 'repos/Supertext/<Repo>/code-scanning/alerts?state=open'`), secret scanning alerts (`…/secret-scanning/alerts?state=open`), open Dependabot PRs and the issue "Broken links in the docs". New workflows and workflow changes must pass actionlint and zizmor; PHP code must pass PHPStan at the repo's level. See `docs/DEVELOPER.md` → *Code quality and security checks*.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable or the module's API key setting.

Lessons from the live API, apply them here: header `Authorization: Supertext-Auth-Key <key>` (strip a pasted prefix), retry HTTP 429 (per-second rate limit), keep a whole text in one `data-st-id` element (each one is translated on its own). **Languages:** only the codes Supertext's `GET features` lists (the SDK's `V1\Languages`): bare sources (`en`); targets regional only for de/en/fr/it/pt (`de-CH`, `en-US`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, bare for the rest (`es`, `nl`, `sl`). **Defaults only where obvious:** the language's *Supertext language* (a select per shop language under *Configure → Languages*, the SDK's `targetOptions()`, preselected with `suggestTarget()`), else an exact match of the locale, else the SDK default (de→de-DE, en→en-US, fr→fr-FR, it→it-IT, pt→pt-PT, zh-CN→zh-Hans, zh-TW→zh-Hant, es-ES→es, …), else the error `unsupported_language` (nothing sent; en-IE, de-LI, gsw need a choice). See `docs/DEVELOPER.md` → *Language codes*.

## This repo

- Before committing: `composer test`, PHP lint, PHPStan (`docs/DEVELOPER.md` → *Code quality and security checks*), `./build.sh`. CI also installs PrestaShop 9 in Docker and translates the demo content against the stand-in.
- Test UI changes in a local PrestaShop with the module mounted (see `docs/DEVELOPER.md` → Local development) and regenerate the screenshots they affect (`tests/docs/screenshots.mjs`). Demo movies: `tests/docs/movie.mjs` (README GIF + MP4) and `tests/docs/movie-install.mjs` (install and configuration, MP4 only), kit in `tests/docs/movie/`; see `docs/DEVELOPER.md` → *Demo movie*.
- End-to-end tests against the real Supertext API: `.github/workflows/e2e.yml` with `tests/e2e/` (see "End-to-end tests" in `docs/DEVELOPER.md`). The key is only the GitHub secret `SUPERTEXT_API_KEY`, set by Remy; sessions don't use a live key. Behaviour changes need matching checks in `tests/e2e/live.mjs`.
- New settings go in `supertext/src/Settings.php`, `views/templates/admin/configure.tpl` **and** the settings table in `docs/INSTALLATION.md`.
- Translation review: per language as `review` in `SUPERTEXT_LANGUAGES` (checkbox in the *Languages* panel of `configure.tpl`); the review service URL is the setting `SUPERTEXT_REVIEW_URL` (env `SUPERTEXT_REVIEW_URL` wins, default `SupertextClient::REVIEW_URL`); `SupertextClient::forReview()` gives the second client, used only by `EntityTranslator`.
- Translated fields live in `supertext/src/Translation/EntityTypes.php`; keep "What is translated" in `docs/DEVELOPER.md` and `docs/USER_GUIDE.md` in sync.
- Keep `supertext/src/Api` and `FieldPlanner` free of PrestaShop classes (unit tests run without PrestaShop).
- All Supertext API calls go through the Supertext PHP SDK, shipped in `supertext/lib/supertext-sdk` with its namespace changed to `Supertext\PrestaShop\Sdk` and wrapped by `supertext/src/Api/SupertextClient.php`. Never edit `supertext/lib/supertext-sdk`: fix the SDK in `Supertext/Supertext-API-SDK-and-Guide` and run `scripts/vendor-sdk.sh`. Every SDK error code needs an arm in `SupertextClient::fromSdk()` and its translation in the XLF files.
- Version reports, the API key check result and the ping use Supertext Plugin Health, shipped the same way in `supertext/lib/supertext-plugin-health` with its namespace changed to `Supertext\PrestaShop\PluginHealth` (key check: the SDK's `checkApiKey()`; glue in `supertext/src/Api/Reporting.php`). Never edit it: fix it in `Supertext/Commando-Center-Supertext-Translation` → `plugin-health/` and run `scripts/vendor-plugin-health.sh <tag>`.
- User-visible strings use the translation domain `Modules.Supertext.Admin`, translated in `supertext/translations/{de-DE,fr-FR,it-IT}/ModulesSupertextAdmin.*.xlf` (English is the source); run `php tools/sync-translations.php` for the regional copies. `tests/Unit/TranslationFilesTest.php` checks they are complete.
- `demo/` is the Railway demo (service *PrestaShop* in *supertext-cms-demos-php*, building `demo/Dockerfile` with context = repo root; set on the service, Railway ignores `railway.json`). `demo/setup.php` seeds languages, sample content and accounts. Demo secrets live only in Railway variables. Don't export-ignore `demo/` or `supertext/` in `.gitattributes`: Railway builds from a `git archive` snapshot.
- PrestaShop 9 installer quirks the demo depends on: it must run from the web root, it expects the admin folder to be `admin` or `admin-dev`, and `--country` must be upper case (`CH`), or it installs the country's languages and fails.
