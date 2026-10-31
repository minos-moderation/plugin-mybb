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
| `bin/verify-mybb-1841.sh` | The plugin on a real MyBB 1.8.41 forum, MySQL or PostgreSQL (see "Verified on a live forum"). |
| `.github/workflows/release.yml` | On a `v*` tag: builds the zip, proves it with `.github/scripts/check-release-archive.sh` and attaches it to the GitHub Release. |
| `tests/` | PHPUnit: stand-ins for MyBB (`Stubs/`, `Support/`), unit tests (`Plugin/`), the end-to-end run (`EndToEnd/`), the Claude Code rules (`Repo/`), the live run on a real forum (`Live/`, not in the plain run) and its recorded answers (`fixtures/mybb-1.8.41/`). |

The classes:

| Class | Job |
|---|---|
| `Platform` | The ONE adapter to MyBB: globals, functions, `Moderation`, tables, the ACP page object. |
| `Settings` | Reads and checks the settings; everything that decides publish or hold is read fail-closed. |
| `Text` | Post → `tekst`: what readers see (MyBB's rendering rules, per forum), the first 3000 characters; the link signals. |
| `TextError` | A PCRE failure inside `Text`: the text could not be read (`error: true`, never `''`). |
| `Gateway` | `POST /api/v1/b2b/oceny` over cURL; reads answers into accepted (`202` only, for the items its `przyjete` names) / retry (429, 408, 5xx, no answer, a 2xx other than 202) / config error. |
| `Submitter` | The hold (validate hooks), the submission (insert-end hooks), resubmission, edits. |
| `Receiver` | The webhook's checklist: method, signature (`Signature::check`; a refusal counted with its reason only), payload, id, claim, apply. |
| `Applier` | A verdict or the failure mode → approve, hold, soft-delete, or publish the masked text. |
| `Task` | Timeouts, due retries, pruning. |
| `Installer` | Settings group, tables, task; the lifecycle functions. |
| `ModQueue` | The ModCP's moderation queue: after each post the plugin holds, a note with its state, the verdict or reason, the categories, the 3000-character note, `wsparcie` and the kept mask. |
| `Admin` | Masked secrets on the settings page, the ACP notice (problems, a configuration error, a 2xx other than 202 at the gateway's address, a daily limit or overload until a given time, refused signatures), the Tools → Logs page with the last 7 days' verdict figures, the posts that need a look and the `wsparcie` posts on a list of their own. |
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

**Subscription notifications** (shared rule D9.8, A4). `insert_post` and `insert_thread`
build them inline (`inc/datahandlers/post.php`, the thread-subscription block of
`insert_post` and the forum-subscription block of `insert_thread`, only when
`visible == 1`); MyBB 1.8.41 has no function that sends them, and neither
`Moderation::approve_*` nor `modcp.php`/`moderation.php` send any on approval — verified on
a live forum (question 2 below). So a post the plugin publishes from the queue — a
`bezpieczne` verdict, a censored post published with its mask, the failure mode in
`fail-open`, on time or after the timeout (D9.10) — notifies its subscribers itself, with
the setting "Powiadamiaj subskrybentów o postach opublikowanych przez wtyczkę"
(`minos_notify_subscribers`, on by default; off when another plugin sends them):
`Applier::publish` reads the thread's and the forum's last post before the approval
(`Platform::lastPosts`), approves, then `Platform::notifySubscribers` runs MyBB's two blocks,
copied: the recipients (instant thread subscriptions, e-mail or PM; forum subscriptions,
e-mail, no banned group; never the author), "not again before the visit" (`lastactive`
after that last post), `canview`/`canviewthreads`/`canonlyviewownthreads` with
`is_moderator`, the recipient's language, MyBB's own texts and excerpt
(`postParser::text_parse_message`, `subscribeexcerpt`), the mail queue
(`$cache->update_mailqueue`) and the PM as `send_pm($pm, -1, true)` sends it (through
`PMDataHandler`, from "MyBB Engine", nothing when `enablepms` is off). The webhook and the
task start MyBB without a session: the board's language is loaded on `$lang` (`global`,
`messages`, as `task.php` does) and `$mybb->user['uid']` is a guest's 0 for the call, so
MyBB's permission functions raise no warning (the live run checks MyBB's error log and
task log). Once only: a late verdict on a post the failure mode published never notifies
again, and a moderator's approval in the ModCP is MyBB's own (nothing sent). Not run: the
`datahandler_post_insert_subscribed` hook, which passes a post datahandler the plugin does
not have. A notification that throws is logged as `error`/`notify` with the post id; the
post stays published. The copied block needs upkeep with every 1.8.x; the live run
compares the counts with a post visible at once (`k31_notifications`).

**`wsparcie`** (shared rule D9.9, A7). Whatever the verdict, an applied delivery with
`wsparcie` adds a report to MyBB's report centre (`Platform::reportForSupport`): the
`reportedcontent` row `add_report($report, 'post')` stores (`id`/`id2`/`id3` the post, its
thread and forum, reporter uid 0, the board's "Other" reason with the plugin's comment —
the verdict, never content, a key or a secret — unread), then
`$cache->update_reportedcontent()`, which MyBB's header reads for its "unread reports"
notice to moderators. `add_report` itself is not called: with the board's `reportmethod`
set to e-mail or PMs it calls `send_report`, which reads the reporting user's session and
the page's `$post`/`$thread`/`$forum` globals, none of which the webhook or the task has.
MyBB 1.8.41's report list (`modcp.php?action=reports`) fills the "last report" cell only
for a reporter with an id, and its template `modcp_reports_report` reads it anyway (on PHP 8
`Undefined array key "lastreporter"` per report): the `modcp_reports_report` hook fills it
for the plugin's own reports (uid 0 on a post flagged `wsparcie`) with the moment and
"Minos", escaped (`ModQueue::onReportRendered`); the live run checks MyBB's error log stays
empty while the list renders. A report of the plugin's still unread for the post is not doubled; a user's own open
report of the same post is not looked at, so the post then has two open reports (known
behaviour: MyBB's `report.php` would add the plugin to the user's report as a second
reporter, which a uid-0 reporter cannot be). One that fails is
logged as `error`/`support` and the verdict stands. Such a post is never deleted,
rejected or hidden automatically: `zablokowane` keeps it held even with "usuń (miękko)",
and a late `zablokowane` (or a late `ocenzurowane` whose mask cannot be published) leaves
a fail-open publication published. Nothing is sent to the author. The ACP page lists
`wsparcie` rows on their own list (`Platform::supportRows`), which the other rows never
push out. (`nieocenione` carries no `wsparcie`: `WebhookPayload::parse` reads none from a
payload without a verdict.) Verified on a live forum: `k41_support`.

**The ModCP's moderation queue** (K-42). `modcp.php?action=modqueue` builds the list of
queued posts (threads) from MyBB's templates into its global `$posts` (`$threads`), runs
`modcp_modqueue_posts_end` (`_threads_end`, no arguments) and only then outputs it; MyBB
1.8.41 has no hook per post. `ModQueue` finds each post by MyBB's own form field
(`posts[<pid>]`, `threads[<tid>]`) and inserts, after the `</tr>` that follows it, a row
`<tr class="minos_modqueue">` with the plugin's note: its state, the verdict or the
failure mode's reason (`minos_reason_*`), the 3000-character note when the text was cut,
the categories, `wsparcie`, and the gateway's masked text of a censored post it kept
(`masked_text`, shared rule A2d) — all escaped, nothing but the plugin's own row. A post
without a row (MyBB held it itself) gets nothing; a theme whose template keeps the field
but has no `</tr>` after it gets the notes at the end of the list, each naming its post, and
one whose template has no such field gets no note at all. Posts published with
the mask (`censored`) are on the ACP page's list of posts that need a look. Verified on a
live forum: `k42_modqueue`.

## What `tekst` is

The text readers SEE, following MyBB's own text renderer
(`Postparser::text_parse_message`) and the forum's `allowhtml`/`allowmycode`/
`allowvideocode` (`get_forum`):

- HTML off (MyBB's default): `<…>` is literal on the page and stays; only decimal numeric
  entities are decoded (`htmlspecialchars_uni` lets them through). HTML on: tags go, the
  text of `alt`/`title` stays, entities are decoded; `<script>`, `<style>`, `<base>` and
  `<meta>` stay as text with what is between them (MyBB escapes them, `parse_message`),
  and an HTML comment goes (`<!-->` and `<!--->` end at once, `-->` or `--!>` end any
  other, one never closed hides the rest) — but only where HTML opens one: one pass from
  left to right takes each tag whole, so `<!--` in a quoted attribute value opens nothing,
  and the content of `<textarea>`, `<title>`, `<xmp>`, `<plaintext>`, `<iframe>`,
  `<noembed>`, `<noframes>` and `<noscript>` stays — as the live run shows
  (`reader-view.json`).
- MyCode: `[quote=NAME]` → "NAME napisał(a):"; `[url=X]Y[/url]` → "Y (X)"; `[img]X[/img]`,
  `[video=…]X[/video]` → X beside other text; `[code]`/`[php]` keep their content; formatting tags go; MyCode
  MyBB would not parse stays literally.
- What heads the post goes in front, a blank line between the parts (`Text::ofPost`): a
  new thread's subject (always escaped, never parsed), then its poll — the question
  escaped like a subject, each option on its own line through the post rules above, as
  `showthread.php` shows them; for a reply, its own subject when it is not one of MyBB's
  defaults ("RE: " and the thread's title, or — for a reply to a post, `posts.replyto` —
  "RE: " and that post's subject without its "RE:"; either cut to 82 characters and "..."
  when longer than 85; or the thread's title itself — `Text::ownSubject`). Whether a
  reply's subject goes with it is decided at its first send and kept in the row
  (`own_subject`), and decided again only by an edit that changes the subject: renaming
  the thread or the replied-to post later does not turn a default into another text. All
  of it is `prefix_len`: a mask there holds the post. Only the first 3000 characters are
  sent.
- A title or a reply's own subject alone is not a text to assess (shared rule F7 in
  client-php's `docs/plugin-behaviour.md`): when the body shows no text and there is no
  poll, `tekst` is `''` and the post gets the failure mode at once as `no_text`, nothing
  sent (fail-open publishes it marked `auto_published`). A poll is text (S5): an empty body
  with a poll sends the title and the poll.
- Neither is an image, a video or a link without a label (F7, `Text::shows`): a body whose
  only visible content is `[img]`/`[img=WxH]`/`[img align=…]` as MyBB's own patterns take
  it (with the forum's image MyCode on; at most one line break before the address), a
  `[video=…]` MyBB embeds (`Text::embedsVideo` follows `mycode_parse_video` step by step:
  a site it knows, a valid URL, an id its rule finds — any other stays as typed), an
  attachment,
  `[url=X]` around a label that shows nothing (blanks, an image, empty formatting), or on an
  HTML forum an `<img>` — its `alt`/`title` notwithstanding — or an `<a>` without text, is
  `no_text`; so is one whose only other characters take no visible place (`Text::INVISIBLE`,
  as in the WordPress plugin, plus marks: white space, `\p{Z}` separators, `\p{Cf}` format
  characters — U+200B, U+2060, U+FEFF, `&#173;`… —, `\p{Cc}` controls, `\p{M}` marks
  without a base such as U+034F or U+FE0F, the Hangul fillers and the blank Braille
  pattern). A bare address the reader sees is text (`https://…`, `[url]X[/url]`, an image
  or video tag MyBB leaves as a link or as typed); beside other text, an image's address
  and a hint are sent as before. The cases are MyBB 1.8.41's reader view as the live run
  recorded it (`reader-view.json`, checked by `ReaderViewTest`). The reading is linear (a
  link is replaced by its label, no recursion), and when PCRE gives up on it the body
  counts as text: in doubt, the post is sent. When what comes before the first `[`, `<`,
  `&` or NUL already shows something, it is on the page as written whatever the forum's
  options, and the answer is yes without that second reading (the same answer; a word
  followed by 2 000 unclosed `[img]` tags no longer costs every image pattern twice).
- The link signals count the links of what is sent, head included (`Text::sourceOf`).
- Every regular expression runs through one checked helper in `Text`: a PCRE failure (a
  post crafted to exhaust the backtrack limit, e.g. a few hundred unclosed `[url=` tags)
  throws `TextError`, and `Text::forPost` returns `error: true` — never `''`. The post then
  gets the failure mode as `text_error` with nothing sent, not even a new thread's subject
  alone (`no_text` is for a post whose body shows no text and that has no poll). Only a
  failure of the match itself counts: a call that exhausts PCRE's JIT stack (a long
  attribute, a 60 000-character quote — markup MyBB renders) runs again with `pcre.jit`
  off, under another key of PHP's pattern cache, and the text is sent; `pcre.jit` is put
  back at once (in a `finally`), as the request had it. The patterns over
  long runs are possessive, so they need no JIT stack per character. On a host whose
  `disable_functions` holds `ini_set` the retry cannot run: such a post (a 60 000-character
  quote or list) stays `text_error`, held or published by the failure mode. What stays
  `text_error` is a post on which PCRE gives up without JIT too, as on the unclosed
  `[url=` tags MyBB itself shows as an empty body (question 1 below; shared rule F7).
- `meta`: `links` (distinct links), `link_domains` (up to 10 registrable domains — the last
  two host labels, or three under a two-label public suffix from a short list; an
  approximation of the Public Suffix List), `author_first_post` for registered authors.

## The row's life

`PREFIX_minos_pending` has one row per held post (`Status`):

```
insert ─► retry ──202──► pending ──webhook──► applying ─► published | censored | held | deleted
            │ ▲                                              superseded | gone
            │ └─ 429/408/5xx/no answer/a 2xx but 202: retry_at = ponow_za_s, or 60 s doubling to 1 h
            │ └─ a 202 whose przyjete leaves the item out: the backoff (nieprzyjete)
            ├─ ponow_za_s ending after submitted_at + timeout: failure mode now (gateway_limit)
            └─ other 4xx, a redirect: failure mode (config_error, the code or http_<n>); ACP notice
pending/retry older than the timeout (≥ 20 min) ─► failure mode (timeout)
fail-open ─► published + auto_published ──late webhook──► applying ─► held | censored | published
fail-closed timeout ─► held (timeout) ──late webhook, post still in the queue──► applying ─► as on time
  (a moderator approved, deleted or edited it, or an author's edit started a new revision ─► nothing)
a "retry" answer ─► the shared pause: nothing sent before its end (below)
an author's edit while retry/pending, or of a post held as timeout/config_error/text_error/no_text,
  that changes the text to send ─► rev + 1, retry due now, a fresh timeout
  (sent by the task as mybb:<pid>.<rev>)
a moderator's edit while retry/pending ─► superseded (the post stays in MyBB's queue)
an edit of an auto-published post ─► auto_published = 0 (a late verdict no longer applies)
applying for more than 5 min ─► back to claimed_from (the task)
```

- Switched off ("Włączona = nie") or deactivated, the plugin does nothing: the webhook
  answers `404` before the signature, the task gives back no claim, applies no failure
  mode and sends nothing (it only forgets old data), and no new post is held. What was
  held stays in MyBB's queue. No usable secret: `401`.
- A delivery whose signature `Signature::check` refuses gets a bare `401`, and the refusal
  is counted in the datacache entry `minos_signature_rejected` (`count`, the last
  `reason` — `malformed`, `stale` or `mismatch` — and its moment `at`; never the body,
  the header or the secret). While it exists every ACP page says so, with what the reason
  points at: the stored secret, the server's clock, or a header that is not the gateway's
  (anyone who knows the webhook address can send one). The next verified delivery and a
  newly saved secret remove it; so does the uninstall.
- Switched off, the task notes it in the datacache entry `minos_switched_off`, and so
  does a deactivation. The first run after the plugin is on again moves every
  `pending`/`retry` row back to `retry` with `submitted_at = retry_at = now` (a fresh
  timeout; `Platform::rearmWaiting`) and sends it in the same run, under the same id.
- Switched on but unable to submit (`Settings::problems()`: no key or secret, an unusable
  gateway address, an unknown profile), a new post is still held, and gets the failure
  mode at once as `not_configured`, unsent. A fresh install starts switched off.
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
- Shared rule D9.10 (A6): a post the failure mode held for its timeout (`fail-closed`:
  `held` with `timeout`) still takes a verdict that comes late, applied by
  `Applier::verdict` as if it had come in time (`bezpieczne` publishes, `zablokowane` and
  `ocenzurowane` by setting, `nieocenione` the failure mode again, a post cut at 3000
  characters as on time). The receiver claims such a row only while its post is still in
  the queue (`visible = 0`; `Platform::claimTimedOut`, one conditional `UPDATE` on
  `status = 'held' AND verdict = 'timeout'` and the revision). A moderator who approved or
  deleted the post decided: the row stays as the failure mode left it. A moderator's edit
  marks it `superseded` (`edited`), an author's edit starts a new revision (the late
  verdict is about the old one), and a text changed past the edit hook fails the digest
  (`text_changed`, the post stays held). A row held for any other reason (a verdict, a
  configuration error, a limit) takes no later verdict; no verdict can come for those.
- A publishing verdict (`bezpieczne`) for a post cut at 3000 characters goes to the failure
  mode (`truncated`): the verdict judged the beginning only.
- Rule D9.3(a): a refusal whose `ponow_za_s` (as the gateway gave it) ends after the row's
  `submitted_at` + timeout gets the failure mode at once (`gateway_limit`). A
  `limit_dobowy_klucza`, `limit_globalny_b2b` or `kolejka_pelna` refusal is kept in the
  datacache entry `minos_refusal` (code, until) for the ACP notice "the gateway does not accept
  until …"; the next `202` clears it, and it is not shown past its end.
- Shared rule F5a: only a `202` accepts, and only the items its `przyjete` names; an item
  it leaves out is sent again after the backoff (`error_code` `nieprzyjete`, no pause). A
  `2xx` other than `202` — also one whose body names every item — is no acceptance:
  something else answers at the gateway's address (a hosting page, a proxy, a wrong
  address). It is retried with the backoff and the shared pause, like a `503`, never a
  configuration error, and kept in the datacache entry `minos_foreign_answer` (code,
  moment) for the ACP notice "something else answers at the gateway's address" until the
  next `202`. A `408` is a network failure: retried, and a pause.
- The shared pause (`Submitter::PAUSE`, datacache entry `minos_pause`: until, code): every
  "retry" answer (any 429, 408, 5xx, a 2xx but 202, no answer) holds every sending back until
  now + `ponow_za_s` as given (60 s without one); a longer pause already kept stays. While
  it lasts a new post is not sent in its author's request and the task sends nothing: a
  due row waits with `retry_at` = the pause's end, or gets the failure mode at once
  (`gateway_limit`) when the pause ends after its timeout. A task run stops sending at the
  first batch the gateway asks to retry. The next `202` clears the pause.
- `author_first_post` is read in the author's request and kept in `first_post` ('1', '0',
  '' for a guest), so a resubmission from the task sends it as first sent.
- The submission records the gateway's answer only while the row is still `retry`, so a
  verdict that arrived before the `202` is not overwritten.
- Before applying, the post is read again: gone → `gone`; no longer unapproved (a moderator
  acted) → `superseded`.
- An edit of a waiting post (`datahandler_post_update`, which MyBB runs before it writes the
  new text) makes the coming verdict stale. By anyone who is not a moderator of the post's
  forum (`is_moderator($fid, '', <current user>)`, the check `update_post` itself makes),
  the row starts a new revision (`Platform::revise`, one conditional `UPDATE`): `rev` + 1,
  `retry` due now, `submitted_at` = now (a fresh timeout), nothing of the earlier attempt
  kept (digest, attempts, outcome). The same for a row the failure mode held because no
  verdict came for its text (`Status::REVISED_REASONS`: `timeout`, `config_error`,
  `text_error`, `no_text` — a body or a poll added later gives it a text). The task sends it as `mybb:<pid>.<rev>`, never the editor's request. The
  receiver accepts `mybb:<pid>` and `mybb:<pid>.<n>` (n ≥ 1, no leading zero) and applies a
  verdict only to the row's current revision: its claim names the revision, so an edit
  between the receiver's read and its claim wins. A verdict for another revision gets `200`
  and nothing else. A moderator's edit of a waiting post, or of one held for its timeout,
  leaves it to humans: `superseded` with `edited`, nothing sent, no late verdict applied,
  listed on the ACP page. Edits of published posts are not
  assessed again (shared rule D9.2, not adopted yet).
- Who can edit a post in the moderation queue, in MyBB 1.8.41: its forum's moderators, and
  its author only while the board setting `showownunapproved` ("Access Own Unapproved
  Threads / Posts", off by default) is on — `editpost.php` and the quick edit
  (`xmlhttp.php`, `edit_post`) refuse the author otherwise. `editpost.php` verified on a
  live forum (question 4 below); the quick edit read in the source.
- One row that throws never stops the others: `try/catch (\Throwable $e)` around one row
  of the task's timeout loop, one row's item in a resubmission, each batch's send, and the
  webhook's `verdict()`/`late()`. `Applier::failedStep` logs `error` with the step as its
  code and the post id (never `getMessage()`), counts it in `apply_errors` and gives the
  claim back (`Platform::countFailure`); the webhook answers `500` so the gateway delivers
  again. After 3 failed steps the row is `held` with `apply_error` (a fail-open post stays
  published, its late window closed) and nothing more is done to the post. A failed
  batch is only logged; its rows stay due. A redelivery after a half-applied verdict finds
  the post changed and records `superseded`.
- A verdict applies only to the text that was sent. The submission keeps the SHA-256 of
  `tekst` in `sent_sha256` (never the text); before any verdict, on time or late, the post
  is rendered again (`Text::forPost` with the forum's current options) and compared. No
  match (a change past the edit hook, another rendering, a row without a digest) → on time
  `held` with `text_changed`, late nothing (the late window closes).
- The masked text replaces the message only when it stands for the whole post (see
  `Applier`): not when the text sent was cut, not unless every character of the mask is
  the one sent or `█` (`Text::maskFits`), not when a mask falls in a new thread's subject,
  not when only the subject remains, and not unless the body sent IS the stored message
  (CRLF aside): a post with MyCode, HTML or entities stays held. A held mask is kept in
  `masked_text` and shown on the ACP page. The original goes into the row first, and the
  new message is read back before the post is approved: a message that could not be
  written keeps the post held.

Every verdict the webhook applies — on time or late, whatever it then does — records the
gateway's answer as delivered (`answer`: `bezpieczne`, `ocenzurowane`, `zablokowane`,
`nieocenione`) and the moment (`answered_at`). The ACP page (`Admin::verdictFigures`) reads
the rows answered in the last 7 days: the median and the 95th percentile (nearest rank) of
`answered_at` − `accepted_at` over those whose `202` was recorded, and a count per answer.
No text, no key, no request to the gateway.

What stays in MyBB's own records after those 30 days (read in the 1.8.41 source): the
post itself; nothing in the moderator log — `Moderation` (`inc/class_moderation.php`)
never calls `log_moderator_action`, the moderator pages (`modcp.php`, `moderation.php`…)
do; no edit history — MyBB 1.8 has none (`posts.edituid`/`edittime` only, and
`Platform::replaceMessage` sets neither); in the task log (`add_task_log`) one line per run
with two counts.

`PREFIX_minos_log` holds events with a code and a post id — never content, a key or a
secret. Log entries are kept 30 days; decided rows — with the verdict, the original text
of a censored post and the mask of a held one — 30 days after their decision (shared rule
D9.11, `Task::DECIDED_RETENTION_S`).

## Tests

```bash
composer install
vendor/bin/phpunit
```

No MyBB checkout is needed. `tests/bootstrap.php` defines MyBB's constants, loads the
stand-ins and includes the REAL `inc/plugins/minos.php` and `inc/tasks/minos.php`, so the
hook functions MyBB would call are the ones tested:

- `tests/Stubs/mybb.php`: `is_moderator`, `forum_permissions`, `rebuild_settings`,
  `fetch_next_run`, `add_task_log`, `get_post_link`, `get_thread_link`, `my_substr`,
  `my_serialize`, `Moderation` (with MyBB's last-post update), `postParser`, `MyLanguage`,
  `PMDataHandler`;
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
censored post published over HTTP. `fixtures/foreign-router.php` stands in for what may
answer at the gateway's address without being the gateway — a `200`, `201` or `204` whose
body names the post, a `408` — which the mock never answers (shared rule F5a). CI runs
everything on PHP 7.4 and 8.3.

`tests/Live/` is not part of that run: it needs a real MyBB 1.8.41 forum and a database,
and runs only through `bin/verify-mybb-1841.sh` (see "Verified on a live forum").
`tests/Plugin/LiveFixturesTest.php` checks the answers it recorded, from the files alone.

## Continuous integration

`.github/workflows/tests.yml` runs the syntax check and `vendor/bin/phpunit` on PHP 7.4 and
8.3 and, on every pull request, the release build and its checks; `release.yml` runs on a
`v*` tag; `live.yml` runs the plugin on a real MyBB 1.8.41 forum (see "Verified on a live
forum"). Every `uses:` in all three is pinned to a full 40-character commit SHA with the
version in a comment (`uses: actions/checkout@<sha>  # v5.0.0`), so a moved tag cannot
change what runs. To refresh a pin, resolve the new tag to its commit —
`git ls-remote https://github.com/<owner>/<action> 'refs/tags/<tag>*'`, taking the peeled
`^{}` line of an annotated tag — check that the commit belongs to that release, and
replace the SHA and the comment together, in every file that uses it.

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
repository (a `vcs` repository entry); `composer.lock` pins the commit: `e5df7d6d`, the
commit the client's `v0.2.0` tag is to name (`Signature::check`). When the tag exists,
`dev-main` will be replaced by `^0.2`, and the `repositories` entry by Packagist if it is
published there. The plugin never forks the client's verification.

## MyBB 1.8 APIs

Checked against the MyBB 1.8.41 source (tag `mybb_1841` of `github.com/mybb/mybb`) by
reading the code; what the live run below goes through is verified on a real forum too:

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
| `polls_do_newpoll_end`, `polls_do_editpoll_end` (no arguments; the thread's row in the global `$thread`, read before the poll was written) | `polls.php` | `Platform::pollThreadFirstPost`, `Submitter::onPollChanged` |
| a thread's poll: `threads.poll` → `polls` (`question`, `options` joined by `\|\|~\|~\|\|`, `numoptions` of them shown); the question through `htmlspecialchars_uni`, each option through `parse_message` with the forum's options | `showthread.php`, `install/resources/mysql_db_tables.php` | `Platform::post`, `Text` |
| a reply's default subject: "RE: " and the thread's title (cut to 82 characters and "..." past 85; for a reply to a post, `replyto`, "RE: " and that post's subject without its `#^RE:\s?#i`); `posts.replyto` | `newreply.php`, `inc/datahandlers/post.php` (`verify_subject`, `insert_post`) | `Text::ownSubject`, `Platform::post` |
| `Moderation::unapprove_posts`, `unapprove_threads` | `inc/class_moderation.php` | `Platform::unapprove` (a late `zablokowane`) |
| subscription notifications: inline in `insert_post`/`insert_thread`, only for `visible == 1`; none on approval — the two blocks copied (`threadsubscriptions`, `forumsubscriptions`, `users.lastactive`/`language`, `usergroups.isbannedgroup`, `mailqueue`, `threads.lastpost`, `forums.lastpost`) | `inc/datahandlers/post.php`, `inc/class_moderation.php`, `modcp.php` | `Platform::lastPosts`, `notifySubscribers` |
| `postParser::text_parse_message`, `parse_badwords`; `my_substr`; `get_thread_link($tid, 0, 'newpost')`; `$mybb->settings['subscribeexcerpt'/'orig_bblanguage'/'bblanguage'/'enablepms']` | `inc/class_parser.php`, `inc/functions.php` | `Platform::notifySubscribers` |
| `MyLanguage` (`set_path`, `set_language`, `load`, `language_exists`, `sprintf`; `messages`: `emailsubject_subscription`, `email_subscription`, `pmsubject_subscription`, `pm_subscription`, `emailsubject_forumsubscription`, `email_forumsubscription`, `emailbit_viewthread`; `global`: `guest`) | `inc/class_language.php`, `task.php` | `Platform::boardLanguage`, `languageFor` |
| `modcp_reports_report` (no arguments; the report in `modcp.php`'s globals `$report` and `$report_data`, before the template reads `$report_data['lastreporter']`) | `modcp.php` | `ModQueue::onReportRendered`, `Platform::modcpReport`, `setModcpReportLastReporter` |
| `add_report($report, 'post')`'s row: `reportedcontent` (`id`, `id2`, `id3`, `uid`, `reportstatus`, `reasonid`, `reason` ≤ 250, `type`, `reports`, `dateline`, `lastreport`, `reporters` by `my_serialize`), `reportreasons` (`<lang:report_reason_other>`, `extra`), `$cache->update_reportedcontent()`; the ModCP's report list (`modcp.php?action=reports`) and the header's `global_unreadreports` | `inc/functions_modcp.php`, `modcp.php`, `global.php`, `inc/class_datacache.php`, `install/resources/mysql_db_inserts.php` | `Platform::reportForSupport` |
| `send_pm($pm, -1, true)` → `PMDataHandler` (`admin_override`, `set_data`, `validate_pm`, `insert_pm`); `$cache->update_mailqueue()` | `inc/functions.php`, `inc/datahandlers/pm.php`, `inc/class_datacache.php` | `Platform::sendPm`, `queueMail` |
| `admin_config_settings_change` before `upsetting` is saved | `admin/modules/config/settings.php` | `Admin::onSettingsChange` |
| `admin_formcontainer_output_row` (references; a setting row's `row_options['id']` is `row_setting_<name>`) | `admin/inc/class_form.php` | `Admin::onFormRow` |
| `admin_tools_action_handler`, `admin_tools_menu_logs`, `admin_tools_permissions`, `admin_load` before the module file is required | `admin/modules/tools/module_meta.php`, `admin/index.php` | `Admin` |
| `modcp_modqueue_posts_end` / `modcp_modqueue_threads_end` (no arguments) with the page's global `$posts` / `$threads` built from `modcp_modqueue_posts_post` / `modcp_modqueue_threads_thread` (the `posts[<pid>]` / `threads[<tid>]` radio fields) | `modcp.php`, `install/resources/mybb_theme.xml` | `ModQueue`, `Platform::modQueueList`, `setModQueueList` |
| `$page->extra_messages`, `output_header`, `output_footer` (exits), `output_confirm_action` (exits), `add_breadcrumb_item` | `admin/inc/class_page.php` | `Platform` |
| tasks: the `tasks` columns, `task_<file>($task)`, `fetch_next_run`, `add_task_log`, `$cache->update_tasks()`, a comma list of minutes | `inc/functions_task.php`, `admin/modules/tools/tasks.php`, `install/resources/mysql_db_tables.php` | `Platform`, `inc/tasks/minos.php` |
| `$lang->load($section, true, true)` with the `english` fallback, `{1}` placeholders | `inc/class_language.php` | `Platform::lang` |
| `$cache->read('plugins')['active']` | `admin/modules/config/plugins.php` | `Platform::isPluginActive` |
| `$cache->read($name, true)` (a hard read: from the database or the cache handler, not the copy loaded with the page), `update($name, $contents)` (`replace_query` on `datacache`, serialized, the whole entry), `delete($name)`. One entry per value of state, so two requests never write back each other's stale copy | `inc/class_datacache.php` | `Platform::state`, `setState`, `removeState` |
| `$db` helpers: `simple_select`, `query`, `fetch_array`, `fetch_field`, `insert_query`, `update_query`, `delete_query`, `escape_string`, `affected_rows`, `table_exists`, `drop_table`, `write_query`, `build_create_table_collation`, `type`; `field_exists($field, $table)` and `add_column($table, $column, $definition)` (prefix added; `ALTER TABLE … ADD`) in every driver, the PDO ones included | `inc/db_mysqli.php`, `inc/db_pgsql.php`, `inc/db_sqlite.php`, `inc/AbstractPdoDbDriver.php`, `inc/db_mysql_pdo.php`, `inc/db_pgsql_pdo.php`, `inc/init.php` | `Platform` |
| the minimal bootstrap `IN_MYBB`, `NO_ONLINE`, `THIS_SCRIPT`, `inc/init.php` (database, settings, cache, plugins; no session) | `inc/init.php`, `task.php` | `minos-webhook.php` |

## Verified on a live forum

`bin/verify-mybb-1841.sh` installs MyBB 1.8.41 from a checkout of its `mybb_1841` tag
(commit `cacad9f3`) with MyBB's OWN installer (`install/index.php`, over HTTP), on MySQL
(`mysqli`) or PostgreSQL (`pgsql`), copies the staged distributable into it
(`bin/build-zip.sh --stage-only`), serves it and the mock gateway with PHP's built-in
server and runs `tests/Live/LiveForumTest.php` through the forum's own pages: the ACP's
plugin and settings pages, `member.php`, `newreply.php`, `newthread.php`, `polls.php`,
`editpost.php`, `modcp.php` (its moderation queue and report list), `task.php`, `showthread.php`, `printthread.php`, `search.php` and the
real `minos-webhook.php`, with deliveries signed by the mock's worker. The test reads the
database to see what happened. It writes to it outside those pages only to set the stage:
`tests/Live/fixtures/minos-live-cli.php` (copied into the forum, never shipped) changes
board settings, a forum's parsing options and users through MyBB's own functions
(`rebuild_settings`, `$cache->update_forums`, `UserDataHandler`), runs MyBB's parser, and
runs the plugin's `Platform` on the real `$db` (the claim question, which puts the row's
status back afterwards); the subscribers' `lastactive` is moved ahead (MyBB notifies once
per visit), and the uninstall test drops a column of a kept table to stand for an older
version's; `minoslive.php`, a
watcher plugin of the test forum, logs the hooks that ran. `.github/workflows/live.yml`
runs it on PHP 7.4 (MySQL 8.0) and PHP 8.3 (MySQL 8.0 and PostgreSQL 16) for pull
requests that change `inc/`, the webhook, the lock, the build or the live files, and on
demand; it uploads the answers and reports, as warnings, any that differ from the
fixtures. Locally (an EMPTY database each time):

```bash
MYBB_DIR=/path/to/mybb-at-mybb_1841 MINOS_LIVE_DB_HOST=127.0.0.1 MINOS_LIVE_DB_NAME=mybb_live \
  MINOS_LIVE_DB_USER=mybb_live MINOS_LIVE_DB_PASS=… bin/verify-mybb-1841.sh mysqli   # or pgsql
```

The run's answers are the fixtures in `tests/fixtures/mybb-1.8.41/` (`live-mysqli.json`,
`live-pgsql.json`: the MyBB commit, the PHP and database versions, the answers;
`reader-view.json`: what MyBB's parser shows of images, videos, links and HTML).
Recorded on PHP 8.3.6, MySQL 8.0.46 and PostgreSQL 16.15; both engines gave the same
answers. The smoke run on both: install and activate through the ACP, the key and secret
saved through the plugin's fields (the page shows prefixes only); an author's reply held
and sent once to the gateway; the signed verdict publishes it (`visible = 1`); a censored
reply published with its mask; a late verdict for a reply MyBB's `task.php` held after the
fail-closed timeout applied as on time, and one for a reply a moderator approved in the
ModCP meanwhile changing nothing (`k43_late_after_timeout`); a reply with `wsparcie`
published and reported in the ModCP's report list and the header, and a blocked one held,
not deleted, with "usuń (miękko)", nothing to the author (`k41_support`); the ModCP's
moderation queue with the plugin's note after a held censored reply (verdict, categories,
mask), a blocked reply cut at 3000 characters and a blocked thread (`k42_modqueue`); a forged signature `401` and named in the ACP; switched
off or deactivated `404`, and a deactivated plugin holds and sends nothing; uninstall
"No" keeps the rows, "Yes" drops the tables. The approval inside the webhook's request
took 36–75 ms end to end (audit measurement C-39).

The questions of the live check (minos-moderation/minos#47), answered:

| # | Question | Answer (fixture key) |
|---|---|---|
| 1 | `Widoczne SLOWO` and n unclosed `[url=http://a.pl]x` (n = 340: 6 135 B; 1000: 18 015 B), reply and thread, default `pcre.backtrack_limit` (1 000 000, JIT on) | MyBB stores all four. Its own `[url=…]` patterns stop with `PREG_BACKTRACK_LIMIT_ERROR` (2), and `showthread.php` shows an EMPTY body — not even `SLOWO` — with or without the parser's output check. The plugin holds each as `text_error`, nothing sent. A reader sees nothing, so sending the raw message instead (audit R1, step 2) is not needed (`q1_pcre`). |
| 2 | Subscribers (thread by e-mail, thread by PM, forum by e-mail) when a held post is approved | A post visible at once queues one e-mail, one PM, one forum e-mail (the control). A reply and a thread the plugin publishes through the webhook with its own notifications off queue none; a reply a moderator approves in the ModCP queue, none either: MyBB 1.8.41 notifies only at insert time (`q2_notifications`). With the plugin's notifications on (K-31), the same publication queues exactly what the control did, the failure mode's publication (fail-open, `task.php`) one e-mail and one PM, the late verdict after it and a blocked reply nothing; no warning in MyBB's task log or error log (`k31_notifications`). |
| 3 | A poll on a thread that waits for its verdict | `newthread.php` with "post a poll" answers with its redirect page; the poll form (`polls.php?action=newpoll`) refuses the author: "The specified thread does not exist". A direct `do_newpoll` POST by the author still creates the poll (hooks `polls_newpoll_start`, `polls_do_newpoll_start`, `_process`, `_end`), and the plugin sends the thread again as revision `.1` with the poll (`q3_polls`). |
| 4 | May a "Registered" author edit their own unapproved post | Not with MyBB's default `showownunapproved` = off (no permission); with it on, `editpost.php` shows the form and saves, and the plugin sends the edit as revision `.1` (`q4_edit_own_unapproved`). |
| 5 | Is a reply's own subject shown | Yes: in the linear view, the threaded view, the printable version and post search results; the plugin sends it in front of the reply (`q5_reply_subject`). |
| 6 | Counters around `approve_*` | A held reply adds nothing to the forum's posts, the thread's replies or the author's post count, and the approval adds one to each — exactly as a ModCP approval does. MyBB itself adds 2 to `threads.unapprovedposts` for a held reply and takes 1 away on approval, for any moderated reply (`insert_post` counts it twice); the plugin takes MyBB's own path, so the same happens with or without it (`q6_counters`). |
| 7 | The tables on MySQL and PostgreSQL; `affected_rows()` after a claim | Created by the ACP's activation on both (`q7_schema`: MySQL's `SHOW CREATE TABLE`, PostgreSQL's columns); a kept table missing a column gets it back on activation, rows kept (`add_column`, `activation_adds_a_missing_column`); the claim's first conditional `UPDATE` reports 1 changed row, a second 0 (`q7_claim`). |

Beside them: in a forum with HTML on, MyBB shows `<script>`/`<style>` as text (it escapes
those tags, `inc/class_parser.php` `parse_message`) and hides HTML comments
(`html_forum_page`, `reader-view.json`); an image (`[img]`, `[img=WxH]`, `[img align=…]`),
a video of a known site and a link whose label shows nothing (`[url=X] [/url]`, an image
inside the link) show no text, while a bare URL and `[url]X[/url]` show the address. For a
moderator signal (K-41, K-42) MyBB 1.8.41 offers, read in the source: `add_report($report, $type = 'post')` (`inc/functions_modcp.php`: a
`reportedcontent` row — `id` the post, `id2` its thread, `id3` its forum, `uid` the
reporter, `reasonid`/`reason` — shown in the ModCP's report list, and with the board's
`reportmethod` set to `email` or `pms` also sent to the moderators by `send_report`), and
`send_pm($pm, -1, true)` (`inc/functions.php`: `subject`, `message`, `touid`,
`receivepms`; `-1` sends as "MyBB Engine"), as `insert_post` uses it for subscriptions.
The plugin chose the report for `wsparcie` (above), and the live run shows it in the
ModCP's report list and the header (`k41_support`).

Still not verified, and why:

- Other plugins hooked on `class_moderation_*`: in the webhook and the task there is no
  session user, as in MyBB's own `task.php`. Which plugins a board runs is the board's;
  the plugin's own failure isolation is tested on stand-ins (`FailureIsolationTest`).
- The ACP pages in a real browser: the live run loads them over HTTP and checks what they
  say; their look (MyBB's own CSS and scripts) is not checked — the plugin adds neither.
- docs.mybb.com was not consulted; the source and the live forum were.
