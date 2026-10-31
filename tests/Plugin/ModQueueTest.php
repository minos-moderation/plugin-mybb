<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Status;
use Minos\MyBB\Tests\Support\Forum;
use Minos\MyBB\Tests\Support\PluginTestCase;

/**
 * The gateway's verdict where a moderator decides (K-42): MyBB's ModCP moderation queue.
 * `modcp.php` builds the list of queued posts (threads) from MyBB 1.8.41's templates into its
 * global `$posts` (`$threads`) and runs `modcp_modqueue_posts_end` (`_threads_end`); the
 * plugin's note follows each post it holds: its state, the verdict and the reason, the
 * categories, `wsparcie`, the 3000-character note and the mask of a censored post it kept.
 * The lists here are rendered with MyBB 1.8.41's default templates. Test data only.
 */
final class ModQueueTest extends PluginTestCase
{
    /**
     * A held censored post whose mask could not be published, cut at 3000 characters: its
     * row in the queue is followed by the verdict, the categories, the 3000-character note
     * and the mask — escaped — and the next post's row by its own note only.
     */
    public function testTheQueueShowsTheVerdictTheReasonAndTheMaskAtEachPost(): void
    {
        $censored = $this->heldReply('[b]<zły>[/b] ' . str_repeat('ą', 3100));
        $this->forum->deliver(self::payload($censored, ['kwalifikacja' => 'ocenzurowane', 'kategorie' => ['wulgaryzmy', 'nekanie'],
            'ocenzurowany' => '<███> ' . str_repeat('ą', 2993)]));
        $waiting = $this->heldReply('Czeka na werdykt.');
        self::assertSame(Status::HELD, $this->forum->row($censored)['status']);

        $html = $this->renderPosts();

        $first = $this->noteAfter($html, 'posts', $censored);
        self::assertStringContainsString('werdykt: ocenzurowane', $first);
        self::assertStringContainsString('kategorie: wulgaryzmy, nekanie', $first);
        self::assertStringContainsString('wpis dłuższy niż 3000 znaków — oceniono początek', $first);
        self::assertStringContainsString('&lt;███&gt;', $first, 'the mask, escaped');
        self::assertStringNotContainsString('<███>', $html);
        $second = $this->noteAfter($html, 'posts', $waiting);
        self::assertStringContainsString('stan: Czeka na werdykt', $second);
        self::assertStringNotContainsString('███', $second, 'each note is its own post\'s');
        foreach ([Forum::KEY, Forum::SECRET] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    /**
     * The reasons the failure mode held a post, and `wsparcie`, are named.
     */
    public function testTheQueueNamesTheFailureModesReasonAndSupport(): void
    {
        $timedOut = $this->heldReply('Bez werdyktu w czasie.');
        $this->forum->platform()->updatePending($timedOut, ['accepted_at' => TIME_NOW - 21 * 60]);
        task_minos(['tid' => 1]);
        $blocked = $this->heldReply('Zablokowana z sygnałem.');
        $this->forum->deliver(self::payload($blocked, ['kwalifikacja' => 'zablokowane', 'kategorie' => ['samookaleczenie'],
            'wsparcie' => true]));

        $html = $this->renderPosts();

        self::assertStringContainsString('werdykt: brak werdyktu w czasie', $this->noteAfter($html, 'posts', $timedOut));
        $note = $this->noteAfter($html, 'posts', $blocked);
        self::assertStringContainsString('werdykt: zablokowane', $note);
        self::assertStringContainsString('wsparcie — autor może potrzebować pomocy', $note);
    }

    /**
     * The threads awaiting moderation: a new thread the plugin holds gets its note too.
     */
    public function testTheThreadQueueShowsTheVerdict(): void
    {
        $handler = $this->forum->write(true, 'Treść zablokowanego wątku.', ['subject' => 'Wątek do kolejki']);
        $pid = (int)$handler->return_values['pid'];
        $tid = (int)$handler->return_values['tid'];
        $this->forum->deliver(self::payload($pid, ['kwalifikacja' => 'zablokowane', 'kategorie' => ['spam']]));

        $GLOBALS['threads'] = self::threadRow($tid, 'Wątek do kolejki', 'Treść zablokowanego wątku.');
        $GLOBALS['plugins']->run_hooks('modcp_modqueue_threads_end');

        $note = $this->noteAfter((string)$GLOBALS['threads'], 'threads', $tid);
        self::assertStringContainsString('werdykt: zablokowane', $note);
        self::assertStringContainsString('kategorie: spam', $note);
    }

    /**
     * A post MyBB holds for its own reasons (the plugin has no row) gets nothing, and a
     * theme without MyBB's rows gets the notes at the end of the list, each naming its post.
     */
    public function testOnlyThePluginsPostsGetANoteAndAThemeWithoutRowsGetsThemAtTheEnd(): void
    {
        $held = $this->heldReply('Zatrzymana przez wtyczkę.');
        $GLOBALS['posts'] = self::postRow(999, 'Inny post', 'Post zatrzymany przez MyBB.') . self::postRow($held, 'RE: Wątek', 'x');
        $GLOBALS['plugins']->run_hooks('modcp_modqueue_posts_end');
        self::assertSame(1, substr_count((string)$GLOBALS['posts'], 'class="minos_modqueue"'));
        self::assertSame('', $this->noteAfter((string)$GLOBALS['posts'], 'posts', 999));

        $GLOBALS['posts'] = '<div><input type="radio" name="posts[' . $held . ']" value="approve" /></div>';
        $GLOBALS['plugins']->run_hooks('modcp_modqueue_posts_end');
        self::assertStringContainsString('#' . $held . ' — ', (string)$GLOBALS['posts']);
    }

    /**
     * A post published with the gateway's mask is on the ACP page's list of posts that need a
     * look, next to the censored posts the plugin kept in the queue.
     */
    public function testCensoredPostsAreOnTheAttentionList(): void
    {
        $published = $this->heldReply('abc zły');
        $this->forum->deliver(self::payload($published, ['kwalifikacja' => 'ocenzurowane', 'ocenzurowany' => 'abc ███']));
        self::assertSame(Status::CENSORED, $this->forum->row($published)['status']);
        self::assertContains($published, array_map('intval', array_column($this->forum->platform()->attentionRows(50), 'pid')));
    }

    /**
     * The list of posts awaiting moderation as `modcp.php` builds it: every reply in the
     * queue through MyBB 1.8.41's `modcp_modqueue_posts_post`, then the hook.
     */
    private function renderPosts(): string
    {
        $html = '';
        $query = $this->forum->db->query('SELECT p.pid, p.subject, p.message FROM ' . TABLE_PREFIX . 'posts p LEFT JOIN '
            . TABLE_PREFIX . "threads t ON (t.tid=p.tid) WHERE p.visible='0' AND t.firstpost != p.pid ORDER BY p.pid DESC");
        while ($post = $this->forum->db->fetch_array($query)) {
            $html .= self::postRow((int)$post['pid'], (string)$post['subject'], (string)$post['message']);
        }
        $GLOBALS['posts'] = $html;
        $GLOBALS['plugins']->run_hooks('modcp_modqueue_posts_end');
        return (string)$GLOBALS['posts'];
    }

    /**
     * The plugin's note after a post's row in the list: what lies between its row and the
     * next post's ('' when none).
     */
    private function noteAfter(string $html, string $list, int $id): string
    {
        $at = strpos($html, 'name="' . $list . '[' . $id . ']"');
        self::assertNotFalse($at, "the guard: the list carries {$list}[{$id}]");
        $rest = (string)substr($html, (int)$at);
        $next = preg_match('/name="' . $list . '\[(?!' . $id . '\])[0-9]+\]"/', $rest, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($rest);
        $part = substr($rest, 0, $next);
        return preg_match('#<tr class="minos_modqueue">(.*?)</tr>#s', $part, $note) ? $note[1] : '';
    }

    /** MyBB 1.8.41's `modcp_modqueue_posts_post`, filled as `modcp.php` fills it. */
    private static function postRow(int $pid, string $subject, string $message): string
    {
        $e = static function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };
        return '<tr><td class="trow1"><a href="showthread.php?pid=' . $pid . '#pid' . $pid . '">' . $e($subject) . '</a></td>'
            . '<td class="trow1" align="center">Autor Testowy</td><td align="center" class="trow1">Przed chwilą</td></tr>'
            . '<tr><td class="trow1" colspan="3"><div class="modqueue_message"><div class="float_right modqueue_controls">'
            . '<label class="label_radio_ignore"><input type="radio" class="radio radio_ignore" name="posts[' . $pid . ']" value="ignore" checked="checked" /> Ignore</label>'
            . '<label class="label_radio_delete"><input type="radio" class="radio radio_delete" name="posts[' . $pid . ']" value="delete" /> Delete</label>'
            . '<label class="label_radio_approve"><input type="radio" class="radio radio_approve" name="posts[' . $pid . ']" value="approve" /> Approve</label>'
            . '</div><div class="modqueue_meta">Forum testowe Wątek</div>' . nl2br($e($message)) . '</div></td></tr>';
    }

    /** MyBB 1.8.41's `modcp_modqueue_threads_thread`, filled as `modcp.php` fills it. */
    private static function threadRow(int $tid, string $subject, string $message): string
    {
        return str_replace(['posts[', 'showthread.php?pid=' . $tid], ['threads[', 'showthread.php?tid=' . $tid],
            self::postRow($tid, $subject, $message));
    }
}
