# Minos for MyBB

A MyBB 1.8 plugin that sends new posts to the Wergiliusz gateway and applies the verdict
the gateway delivers to a signed webhook. Part of Minos; its backlog item is
`minos-moderation/minos#6`.

## The boundary
- The plugin talks only to the gateway, over HTTPS, with the forum's key. The key and the
  webhook secret never reach a log, a page or a public file.
- The gateway decides and the plugin applies. It never guesses a verdict: `nieocenione`
  goes to the administrator's fail-open or fail-closed setting.
- The contract is `docs/contract.md` in `minos-moderation/client-php`; its receiving
  checklist is binding: read the raw body first, verify the signature, drop repeated
  deliveries, answer 2xx fast.
- A PHP plugin bundles `minos-moderation/client-php` at a pinned version and never forks
  its verification. Another language implements it and tests it on the gateway's signature
  vector, copied byte for byte.

## Code
- Code, comments and commits in English. Everything an administrator or a user reads is
  in Polish.
- Wire strings (JSON keys, codes, headers, enum values) are Polish and never renamed.
- The oldest versions the plugin promises: MyBB 1.8 (APIs checked on 1.8.41) and PHP 7.4
  (no PHP 8 syntax), tested in CI on PHP 7.4 and 8.3.
- No real posts in tests: use the mock gateway from `minos-moderation/client-php`.
- Every MyBB touch point is `Platform` (`inc/plugins/minos/src/`); the tests run it on
  stand-ins — rules in `tests/CLAUDE.md`. Layout and unverified APIs: `docs/development.md`.
- Never `git add -A`. Sessions open PRs; only the owner or the coordinating session merges.
- Model-pinned agents in `.claude/agents/`, copied from `client-php` and fitted to the
  plugin's paths (`programista-prosty`'s risk list above all).
- This file stays small (`tests/Repo/ClaudeRulesTest.php` pins its size).
