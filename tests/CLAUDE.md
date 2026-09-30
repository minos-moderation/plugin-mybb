# The tests

- **No MyBB checkout.** `Stubs/mybb.php` (functions, `Moderation`) and `Support/` (`$db` on
  SQLite, `$mybb`, `$cache`, `$lang`, `$plugins`, `$page`) stand in for MyBB 1.8.41. A new
  MyBB call goes into `Platform` first, then gets a stand-in here with MyBB's semantics.
- **Through the real entry points**: `bootstrap.php` includes `inc/plugins/minos.php` and
  the task file once; `Support\Forum::write` posts through the hook functions, with
  MyBB's own visibility rule.
- **Deliveries are signed with `Signature::sign`**, never built by hand.
- **`EndToEndTest` starts real processes**: `proc_open` with an array, never a string,
  and every port is asserted closed afterwards. It serves the build's staged tree.
- **Test data only**: no real posts, keys or secrets.
