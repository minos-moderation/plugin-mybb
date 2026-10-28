---
name: programista-prosty
description: FIXTURE for ClaudeRulesTest - a Sonnet writer whose "You may change" list names a risk path (Text.php, as an exception written there instead of on the risk list). The test must reject it.
model: sonnet
effort: medium
tools: Read, Grep, Glob, Bash, Edit, Write
---
You implement one simple change. You may change:
- `README.md`, `docs/development.md` and the language files;
- the comments of `inc/plugins/minos/src/Text.php`;
- test fixtures and test data.

Risk list — STOP before editing:
- `minos-webhook.php`, `inc/plugins/minos.php`, `inc/plugins/minos/autoload.php`;
- `inc/plugins/minos/src/`: `Receiver.php`, `Submitter.php`, `Applier.php`, `Gateway.php`,
  `Text.php`, `Settings.php`, `Installer.php`, `Admin.php`, `Platform.php`, `Task.php`;
- `inc/tasks/minos.php`, `bin/build-zip.sh`, `.github/`, `.claude/`, every `CLAUDE.md`,
  `composer.json`, `composer.lock`, `phpunit.xml.dist`, `tests/Stubs/`, `tests/Support/`.
