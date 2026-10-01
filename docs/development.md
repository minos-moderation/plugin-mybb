# Developing the MyBB plugin

The plugin's user manual is the Polish `README.md`; this page is for developers. The
contract with the gateway is `docs/contract.md` in `minos-moderation/client-php`
(installed at `vendor/minos-moderation/client-php/docs/contract.md`).

## Layout

The repository root mirrors a forum's root, so every plugin file is where it will be on a
forum:

| Path | What |
|---|---|
| `minos-webhook.php` | The webhook entry: reads the raw body, starts MyBB with `inc/init.php` only (like `task.php`), hands over to `Receiver`. |
| `inc/plugins/minos.php` | The entry file MyBB includes: hook registrations, `minos_info/_install/_is_installed/_uninstall/_activate/_deactivate`. |
| `inc/plugins/minos/autoload.php` | The plugin's own class loader (MyBB has no Composer). |
| `inc/plugins/minos/src/` | The classes, namespace `Minos\MyBB`. |
| `inc/tasks/minos.php` | `task_minos($task)`, run every 5 minutes by MyBB's task system. |
| `inc/languages/{polish,english}/minos.lang.php` | Polish texts, byte-identical: MyBB falls back to `english/` when the board's pack has no file, and the plugin serves Polish forums. |
| `bin/build-zip.sh` | Builds `build/minos-mybb-<version>.zip` (`Upload/`, `README.md`, `LICENSE`). |
| `.github/workflows/release.yml` | On a `v*` tag: builds the zip, proves it with `.github/scripts/check-release-archive.sh` and attaches it to the GitHub Release. |
| `tests/` | PHPUnit: stand-ins for MyBB (`Stubs/`, `Support/`), unit tests (`Plugin/`), the end-to-end run (`EndToEnd/`), the Claude Code rules (`Repo/`). |

The classes:

| Class | Job |
|---|---|
| `Platform` | The ONE adapter to MyBB: globals, functions, `Moderation`, tables, the ACP page object. |
| `Settings` | Reads and checks the settings; everything that decides publish or hold is read fail-closed. |
| `Text` | Post → `tekst`: what readers see (MyBB's rendering rules, per forum), the first 3000 characters; the link signals. |
| `Gateway` | `POST /api/v1/b2b/oceny` over cURL; reads answers into accepted / retry / config error. |
| `Submitter` | The hold (validate hooks), the submission (insert-end hooks), resubmission, edits. |
| `Receiver` | The webhook's checklist: method, signature, payload, id, claim, apply. |
| `Applier` | A verdict or the failure mode → approve, hold, soft-delete, or publish the masked text. |
| `Task` | Timeouts, due retries, pruning. |
| `Installer` | Settings group, tables, task; the lifecycle functions. |
| `Admin` | Masked secrets on the settings page, the ACP notice, the Tools → Logs page. |
| `Plugin` | Builds the objects once per request. |

## How the plugin holds a post

`PostDataHandler::insert_post` and `insert_thread` decide visibility in a LOCAL variable
before their `datahandler_post_insert_post`/`_thread` hooks run, and use that variable
afterwards for the user's post count, the thread's and forum's counters, the last-post
data and the subscription e-mails (which quote the post). Setting `visible = 0` in the
insert data from those hooks would leave all of that as if the post were public.

The one input MyBB reads for that decision is `$mybb->user['moderateposts']`, checked when
the poster is the current user. So `Submitter::onValidate` (hooked on
`datahandler_post_validate_post`/`_thread`) raises it for this request only, and MyBB takes
its own moderated path. `Submitter::onInserted` (on `datahandler_post_insert_post_end`/
`_thread_end`, where `return_values` holds `pid`, `tid` and `visible`) puts it back, records
the row and sends the item.

A post MyBB would hold anyway (the forum moderates new posts or threads for the user's
group, or the user is under moderation) is left alone: humans asked for those. A post
whose validation already failed (`get_errors()`) raises no flag, and `newreply_start` /
`newthread_start` (the form shown again after a later validate hook refused the post) put
it back.

**Subscription notifications are not sent** for held posts. `insert_post` and
`insert_thread` build them inline (`inc/datahandlers/post.php`, the thread-subscription
block of `insert_post` and the forum-subscription block of `insert_thread`, only when
`visible == 1`); MyBB 1.8.41 has no function that sends them, and neither
`Moderation::approve_*` nor `modcp.php`/`moderation.php` send any on approval — the same
as for manually moderated posts. Sending them would mean copying that block (per-subscriber
permissions, languages, `send_pm`, the mail queue); that is a separate decision.

## What `tekst` is

The text readers SEE, following MyBB's own text renderer
(`Postparser::text_parse_message`) and the forum's `allowhtml`/`allowmycode`/
`allowvideocode` (`get_forum`):

- HTML off (MyBB's default): `<…>` is literal on the page and stays; only decimal numeric
  entities are decoded (`htmlspecialchars_uni` lets them through). HTML on: tags go, the
  text of `alt`/`title` stays, `<script>`/`<style>` go with their content, entities are
  decoded.
- MyCode: `[quote=NAME]` → "NAME napisał(a):"; `[url=X]Y[/url]` → "Y (X)"; `[img]X[/img]`,
  `[video=…]X[/video]` → X; `[code]`/`[php]` keep their content; formatting tags go; MyCode
  MyBB would not parse stays literally.
- A new thread's subject goes in front (always escaped, never parsed). Only the first 3000
  characters are sent.
- `meta`: `links` (distinct links), `link_domains` (up to 10 registrable domains — the last
  two host labels, or three under a two-label public suffix from a short list; an
  approximation of the Public Suffix List), `author_first_post` for registered authors.

## The row's life

`PREFIX_minos_pending` has one row per held post (`Status`):

```
insert ─► retry ──202──► pending ──webhook──► applying ─► published | censored | held | deleted
            │ ▲                                              superseded | gone
            │ └─ 429/503/no answer: retry_at = ponow_za_s, or 60 s doubling to 1 h
            └─ other 4xx: failure mode (config_error); ACP notice
pending/retry older than the timeout (≥ 20 min) ─► failure mode (timeout)
fail-open ─► published + auto_published ──late webhook──► applying ─► held | censored | published
an edit while retry/pending ─► superseded (the post stays in MyBB's queue)
an edit of an auto-published post ─► auto_published = 0 (a late verdict no longer applies)
applying for more than 5 min ─► back to claimed_from (the task)
```

- A row starts as `retry` with `retry_at` a minute ahead, so a request that dies half-way
  is sent again by the task and never mistaken for an accepted one.
- `Platform::claimPending` moves a row from `pending`/`retry` (or, for a late verdict, from
  an `auto_published` `published`) to `applying` with a conditional `UPDATE`, recording
  `claimed_from` and `claimed_at`; only the caller that changed it applies anything.
  Repeated and concurrent deliveries, and the task beside the webhook, apply nothing twice.
  The task gives back claims older than 5 minutes (their holder died).
- The receive timeout is at least 20 minutes (the gateway's 15-minute TTL plus grace). A
  post the failure mode published (`fail-open`) is marked `auto_published`; a verdict that
  still arrives is applied by `Applier::late` while the post is public and untouched
  (`zablokowane` → `unapprove`, `ocenzurowane` → by setting). A moderator's action (the
  post's visibility changed) or an edit (the update hook clears the mark) makes the human
  decision final.
- A publishing verdict (`bezpieczne`) for a post cut at 3000 characters goes to the failure
  mode (`truncated`): the verdict judged the beginning only.
- The submission records the gateway's answer only while the row is still `retry`, so a
  verdict that arrived before the `202` is not overwritten.
- Before applying, the post is read again: gone → `gone`; no longer unapproved (a moderator
  acted) → `superseded`.
- The masked text replaces the message only when it stands for the whole post (see
  `Applier`): not when the text sent was cut, not when its length differs from what was
  sent (the contract masks one `█` per character), not when a mask falls in a new thread's
  subject, and not when only the subject remains. The original goes into the row first,
  and the new message is read back before the post is approved: a message that could not
  be written keeps the post held.

`PREFIX_minos_log` holds events with a code and a post id — never content, a key or a
secret. Log entries are kept 30 days, decided rows 90 days.

## Tests

```bash
composer install
vendor/bin/phpunit
```

No MyBB checkout is needed. `tests/bootstrap.php` defines MyBB's constants, loads the
stand-ins and includes the REAL `inc/plugins/minos.php` and `inc/tasks/minos.php`, so the
hook functions MyBB would call are the ones tested:

- `tests/Stubs/mybb.php`: `is_moderator`, `forum_permissions`, `rebuild_settings`,
  `fetch_next_run`, `add_task_log`, `get_post_link`, `Moderation`;
- `tests/Support/`: `$db` over SQLite (`FakeDb`), `$mybb`, `$cache`, `$lang`, `$plugins`
  (with MyBB's "a truthy return replaces the arguments" rule), `$page`, and `Forum`, which
  posts through the hooks with MyBB 1.8.41's visibility rule;
- deliveries are signed with `Minos\Client\Signature::sign`.

`tests/EndToEnd/EndToEndTest.php` stages the distributable with
`bin/build-zip.sh --stage-only`, serves the staged forum root with PHP's built-in server
(the real `minos-webhook.php`, with a fake `inc/init.php` over the SQLite file the test
shares — the plugin's classes and the client library then load from the STAGED tree
through the plugin's own autoloader) and the mock gateway from
`vendor/minos-moderation/client-php/mock-gateway`. `fixtures/forum-cli.php`, run from the
staged root too, installs the plugin and posts through its hooks with the real cURL
transport, so both sides run the distributable; the mock's worker delivers, including a
censored post published over HTTP. CI runs everything on PHP 7.4 and 8.3.

## Against the mock gateway by hand

The mock is in `vendor/minos-moderation/client-php/mock-gateway` (its manual: the
client's `README.md`, "The mock gateway"). With a MyBB 1.8 forum on this machine:

```bash
export MINOS_MOCK_WEBHOOK_URL=http://localhost/forum/minos-webhook.php
php -S 127.0.0.1:8100 -t vendor/minos-moderation/client-php/mock-gateway/public   # terminal 1
php vendor/minos-moderation/client-php/mock-gateway/bin/worker.php                 # terminal 2
```

In the forum's ACP set the gateway address to `http://127.0.0.1:8100`, the key to
`wgb2b_atrapa_minos_0000000000000000` and the webhook secret to
`atrapa-minos-sekret-webhooka-tylko-lokalnie` (the mock's defaults). Then choose each
verdict with markers in a post: `[minos:blokuj]`, `[minos:cenzuruj]` with `[[fragment]]`,
`[minos:nieocenione]`, `[minos:kategoria=samookaleczenie]`, `[minos:dwa-razy]`,
`[minos:zly-podpis]`, `[minos:cisza]`. Never post real users' content to the mock: it keeps
its queue as plain JSON on disk.

The mock masks `[[głupi]]` as `[[█████]]`, one character per character as the contract
does (client-php `f85c6ed` and later), so a `[minos:cenzuruj]` post is published with its
masked text; the plugin refuses a masked text of another length.

## Building the distributable

```bash
bin/build-zip.sh          # build/minos-mybb-<version>.zip
```

It runs `composer install --no-dev` from `composer.lock` in a temporary directory and
copies only the client's `src/`, `LICENSE` and `composer.json` under
`Upload/inc/plugins/minos/vendor/minos-moderation/client-php/` — never its mock gateway or
tests, which must not become reachable under a forum's web root. `MINOS_VENDOR_DIR`
points it at an existing `vendor/` instead (the tests use the repository's).

## Releasing

The version lives in ONE place the release workflow checks: `Installer::VERSION` in `inc/plugins/minos/src/Installer.php`, which `minos_info()` reports to MyBB's plugin list and `bin/build-zip.sh` puts in the zip's name. The repository
keeps no changelog; the release notes are GitHub's generated ones. The workflow, not a
person, creates the release, and only after its checks pass.

1. Bump `Installer::VERSION` in a pull request, and merge it. On every pull request the `release-archive` job
   of `tests.yml` already runs the release build and checks against the declared version.
2. The dry run on `main`: Actions → Release → "Run workflow", branch `main`, the tag to
   be (`v0.2.0`). It builds and checks exactly as a tag push does and keeps the zip
   as the run's `release-archive` artifact (7 days); see it green.
3. The owner creates and pushes the TAG ONLY, on the commit the dry run checked, from a
   local clone: `git tag -a v0.2.0 -m v0.2.0 <commit>` and `git push origin v0.2.0`.
   Not GitHub's "Draft a new release" form: a tag created there is published together
   with its release, before any check. Tag pushes from Claude Code sessions are refused.
4. The tag's push starts `.github/workflows/release.yml`, which builds `build/minos-mybb-<version>.zip` with `bin/build-zip.sh` on PHP 7.4 and lists it,
   failing on any hit: `composer.lock`, `tests/`, `phpunit*`, the mock gateway, `.git*`, `CLAUDE.md`, `.claude/`, `.github/`, Composer's `installed.json`; it also fails unless `LICENSE` at the zip's root is inside and not
   blank, and the zip's `Installer::VERSION` equals the tag without the `v` (the message names both). Only
   then the `publish` job creates the release with the zip attached (`--verify-tag`,
   generated notes, a pre-release for a tag with `-`). gh creates it as a draft, uploads,
   then publishes, so a failed upload leaves a draft, never a release without its asset.

When a release for the tag already exists at that point, `publish` fails and attaches
nothing: such a release was published unchecked. Delete it (keep the tag) and re-run the
failed jobs. Nothing replaces an asset (`--clobber` is never used), so a second upload
fails loudly. A failed check publishes nothing: delete the tag, fix `main`, start again
from step 1. By hand: `bin/build-zip.sh`, then `.github/scripts/check-release-archive.sh build/minos-mybb-0.1.0.zip v0.1.0`.

Nothing in this repository enforces that only the owner tags (the session refusal lives
outside GitHub): the owner should add a tag ruleset on `v*` that only they may bypass, or
a `release` environment with a required reviewer on the `publish` job.

## Composer

`composer.json` requires `minos-moderation/client-php` at `dev-main` from its GitHub
repository (a `vcs` repository entry); `composer.lock` pins the commit. When the client is
tagged, `dev-main` will be replaced by `^0.1`, and the `repositories` entry by Packagist
if it is published there. The plugin never forks the client's verification.

## MyBB 1.8 APIs

Checked against the MyBB 1.8.41 source (tag `mybb_1841` of `github.com/mybb/mybb`), by
reading the code, not by running a live forum:

| API | Where in MyBB | Used by |
|---|---|---|
| `_info` / `_install` / `_is_installed` / `_uninstall` / `_activate` / `_deactivate`, `compatibility` `18*` | `admin/modules/config/plugins.php`, `inc/class_plugins.php` (`is_compatible`) | `inc/plugins/minos.php` |
| `$plugins->add_hook`, `run_hooks` (a truthy return value replaces the arguments) | `inc/class_plugins.php` | every hook function returns nothing |
| `datahandler_post_validate_post` / `_thread` (`$this`, `method`, `data`) | `inc/datahandlers/post.php` | `Submitter::onValidate` |
| `$mybb->user['moderateposts']` in `insert_post` / `insert_thread` | `inc/datahandlers/post.php` | the hold |
| `datahandler_post_insert_post_end` / `_thread_end` (`return_values`) | `inc/datahandlers/post.php` | `Submitter::onInserted` |
| `datahandler_post_update` (an edit keeps an unapproved post unapproved) | `inc/datahandlers/post.php` (`update_post`) | `Submitter::onUpdated` |
| `Moderation::approve_posts`, `approve_threads`, `soft_delete_posts`, `soft_delete_threads` (counters and last post rebuilt inside) | `inc/class_moderation.php` | `Platform::approve`, `softDelete` |
| `is_moderator($fid, '', $uid)` (super moderators included), `forum_permissions()['modposts'/'modthreads']` | `inc/functions.php` | `Platform` |
| `get_post_link`, `rebuild_settings` | `inc/functions.php` | `Platform` |
| settings groups and settings (the `hello` plugin's pattern), `optionscode` types `onoff`, `yesno`, `text`, `numeric` with `min`/`max`, `select`, `forumselect`, `php` (evaluated as a double-quoted string; its "Edit setting" page refused; disabled while the plugin is deactivated) | `admin/modules/config/settings.php`, `inc/plugins/hello.php` | `Installer`, `Platform::saveSettings`, `setOptionscode` |
| `newreply_start` / `newthread_start` (the form shown again after errors), `DataHandler::get_errors` | `newreply.php`, `newthread.php`, `inc/datahandler.php` | `Submitter` |
| `get_forum()['allowhtml'/'allowmycode'/'allowimgcode'/'allowvideocode']`, `htmlspecialchars_uni`, `Postparser::text_parse_message` (the rules `Text` follows) | `inc/functions.php`, `inc/class_parser.php` | `Platform::forumParsing`, `Text` |
| `Moderation::unapprove_posts`, `unapprove_threads` | `inc/class_moderation.php` | `Platform::unapprove` (a late `zablokowane`) |
| subscription notifications: inline in `insert_post`/`insert_thread`, only for `visible == 1`; none on approval | `inc/datahandlers/post.php`, `inc/class_moderation.php`, `modcp.php` | not sent (see above) |
| `admin_config_settings_change` before `upsetting` is saved | `admin/modules/config/settings.php` | `Admin::onSettingsChange` |
| `admin_formcontainer_output_row` (references; a setting row's `row_options['id']` is `row_setting_<name>`) | `admin/inc/class_form.php` | `Admin::onFormRow` |
| `admin_tools_action_handler`, `admin_tools_menu_logs`, `admin_tools_permissions`, `admin_load` before the module file is required | `admin/modules/tools/module_meta.php`, `admin/index.php` | `Admin` |
| `$page->extra_messages`, `output_header`, `output_footer` (exits), `output_confirm_action` (exits), `add_breadcrumb_item` | `admin/inc/class_page.php` | `Platform` |
| tasks: the `tasks` columns, `task_<file>($task)`, `fetch_next_run`, `add_task_log`, `$cache->update_tasks()`, a comma list of minutes | `inc/functions_task.php`, `admin/modules/tools/tasks.php`, `install/resources/mysql_db_tables.php` | `Platform`, `inc/tasks/minos.php` |
| `$lang->load($section, true, true)` with the `english` fallback, `{1}` placeholders | `inc/class_language.php` | `Platform::lang` |
| `$cache->read('plugins')['active']` | `admin/modules/config/plugins.php` | `Platform::isPluginActive` |
| `$db` helpers: `simple_select`, `query`, `fetch_array`, `fetch_field`, `insert_query`, `update_query`, `delete_query`, `escape_string`, `affected_rows`, `table_exists`, `drop_table`, `write_query`, `build_create_table_collation`, `type` | `inc/db_mysqli.php`, `inc/db_pgsql.php`, `inc/db_sqlite.php`, `inc/AbstractPdoDbDriver.php`, `inc/init.php` | `Platform` |
| the minimal bootstrap `IN_MYBB`, `NO_ONLINE`, `THIS_SCRIPT`, `inc/init.php` (database, settings, cache, plugins; no session) | `inc/init.php`, `task.php` | `minos-webhook.php` |

Not verified — to check on a live forum before a release:

- The MySQL and PostgreSQL `CREATE TABLE` statements (no `ENGINE` clause: the server's
  default) and `CREATE INDEX IF NOT EXISTS` (PostgreSQL 9.5+): only the SQLite ones run in
  the tests.
- The pending table gained `auto_published`, `claimed_at` and `claimed_from` before any
  release; a table created by the unreleased first draft (`0146a3a`) lacks them — drop it
  (uninstall with "Tak") before reinstalling.
- `affected_rows()` after the claim's conditional `UPDATE` on MySQL (it counts changed
  rows; the claim always changes `status`, so it should read 1).
- Other plugins hooked on `class_moderation_*`: in the webhook and the task there is no
  session user, as in MyBB's own `task.php`.
- The ACP rendering of the settings page and the Tools → Logs page in a real browser
  (the `php` setting type is tested by running MyBB's own evaluation line).
- docs.mybb.com was not consulted; the source was.
