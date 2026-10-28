<?php

/*
 * The live run's helper INSIDE the forum: copied into the forum root by
 * `bin/verify-mybb-1841.sh` (never shipped), it starts MyBB 1.8.41 the way `task.php`
 * does (`inc/init.php`), runs one operation and prints its result as JSON. The scenarios
 * themselves go through the forum's pages; this helper only sets the board up (settings,
 * users) through MyBB's own functions, and reads what only PHP inside MyBB can see: the
 * parser's output for a message, and the plugin's own `Platform` on the real `$db`.
 *
 * Usage: php minos-live-cli.php < {"op": "...", ...}
 */

define('IN_MYBB', 1);
define('NO_ONLINE', 1);
define('THIS_SCRIPT', 'minos-live-cli.php');

$minosLiveRequest = json_decode((string)stream_get_contents(STDIN), true);
require_once __DIR__ . '/inc/init.php';
$lang->set_language($mybb->settings['bblanguage']);
$lang->load('global');
$lang->load('messages');

/**
 * @param array<string,mixed> $request
 * @return mixed
 */
function minos_live_run(array $request)
{
    global $db, $mybb, $cache;
    switch ($request['op']) {
        case 'settings':
            // MyBB's own way to change a board setting outside the ACP form.
            foreach ($request['values'] as $name => $value) {
                $db->update_query('settings', ['value' => $db->escape_string((string)$value)],
                    "name='" . $db->escape_string($name) . "'");
            }
            rebuild_settings();
            return true;

        case 'forum':
            // A forum's parsing options (the ACP's forum form writes the same columns).
            $db->update_query('forums', $request['values'], "fid='" . (int)$request['fid'] . "'");
            $cache->update_forums();
            return true;

        case 'user':
            require_once MYBB_ROOT . 'inc/datahandlers/user.php';
            $handler = new UserDataHandler('insert');
            $handler->set_data([
                'username' => $request['username'], 'password' => $request['password'],
                'password2' => $request['password'], 'email' => $request['email'], 'email2' => $request['email'],
                'usergroup' => (int)($request['usergroup'] ?? 2), 'displaygroup' => 0,
                'regip' => '203.0.113.10', 'receivepms' => 1, 'pmnotice' => 1,
            ]);
            if (!$handler->validate_user()) {
                return ['errors' => $handler->get_friendly_errors()];
            }
            $user = $handler->insert_user();
            return (int)$user['uid'];

        case 'parse':
            // What a reader sees: the post body as `build_postbit` parses it, with the forum's
            // own options.
            require_once MYBB_ROOT . 'inc/class_parser.php';
            $forum = get_forum((int)$request['fid']);
            $parser = new postParser();
            $options = [
                'allow_html' => $forum['allowhtml'], 'allow_mycode' => $forum['allowmycode'],
                'allow_smilies' => $forum['allowsmilies'], 'allow_imgcode' => $forum['allowimgcode'],
                'allow_videocode' => $forum['allowvideocode'], 'me_username' => 'Autor', 'filter_badwords' => 1,
            ];
            $html = $parser->parse_message((string)$request['message'], $options);
            $lastError = preg_last_error();
            // The same without the output check MyBB makes when HTML is off
            // (`output_allowed`, which turns invalid output into ''): is an empty body
            // PCRE's doing or the check's?
            $unchecked = new postParser();
            $unchecked->output_validation_policy = postParser::VALIDATION_DISABLE;
            $raw = $unchecked->parse_message((string)$request['message'], $options);
            // Each of the parser's own MyCode patterns, run alone on the message: which of
            // them PCRE gives up on, and with which error.
            $failures = [];
            if (is_array($parser->mycode_cache)) {
                $patterns = $parser->mycode_cache['standard']['find'];
                foreach (['callback', 'nestable', 'nestable_callback'] as $kind) {
                    foreach ($parser->mycode_cache[$kind] ?? [] as $code) {
                        $patterns[] = $code['find'];
                    }
                }
                foreach ($patterns as $pattern) {
                    if (@preg_replace($pattern, '', (string)$request['message']) === null) {
                        $failures[] = ['pattern' => $pattern, 'error' => preg_last_error()];
                    }
                }
            }
            return [
                'html' => $html, 'preg_last_error' => $lastError, 'pattern_failures' => $failures,
                'html_without_output_check' => is_string($raw) ? substr($raw, 0, 300) : null,
                'backtrack_limit' => (string)ini_get('pcre.backtrack_limit'), 'jit' => (string)ini_get('pcre.jit'),
            ];

        case 'plugin_text':
            // The plugin's own reading of a stored post, through its Platform on this $db.
            $plugin = \Minos\MyBB\Plugin::instance();
            $post = $plugin->platform->post((int)$request['pid']);
            if ($post === null) {
                return null;
            }
            return \Minos\MyBB\Text::ofPost($post, $post['first'], $plugin->platform->forumParsing((int)$post['fid']));

        case 'claim':
            // The claim's conditional UPDATE and `affected_rows()` on this engine: the first
            // claim of a waiting row wins, the second finds nothing to change.
            $platform = \Minos\MyBB\Plugin::instance()->platform;
            $row = $platform->pending((int)$request['pid']);
            $first = $platform->claimPending((int)$request['pid'], TIME_NOW, \Minos\MyBB\Status::OPEN, false, (int)$row['rev']);
            $affectedFirst = $db->affected_rows();
            $second = $platform->claimPending((int)$request['pid'], TIME_NOW, \Minos\MyBB\Status::OPEN, false, (int)$row['rev']);
            $affectedSecond = $db->affected_rows();
            // Back to where it was, so the scenario can go on.
            $db->update_query('minos_pending', ['status' => $db->escape_string((string)$row['status'])], "pid='" . (int)$request['pid'] . "'");
            return ['first' => $first, 'second' => $second, 'affected_first' => $affectedFirst, 'affected_second' => $affectedSecond];

        case 'schema':
            $tables = [];
            foreach (['minos_pending', 'minos_log'] as $table) {
                if ($db->type === 'pgsql' || $db->type === 'pgsql_pdo') {
                    $query = $db->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name='"
                        . TABLE_PREFIX . $table . "' ORDER BY ordinal_position");
                    $columns = [];
                    while ($column = $db->fetch_array($query)) {
                        $columns[] = $column;
                    }
                    $tables[$table] = $columns;
                } else {
                    $query = $db->query('SHOW CREATE TABLE ' . TABLE_PREFIX . $table);
                    $tables[$table] = (string)array_values($db->fetch_array($query))[1];
                }
            }
            return ['engine' => $db->type, 'version' => $db->get_version(), 'tables' => $tables];

        case 'state':
            $value = $cache->read((string)$request['name'], true);
            return $value === false ? null : $value;

        default:
            throw new RuntimeException('unknown op');
    }
}

echo json_encode(minos_live_run(is_array($minosLiveRequest) ? $minosLiveRequest : ['op' => '']),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
