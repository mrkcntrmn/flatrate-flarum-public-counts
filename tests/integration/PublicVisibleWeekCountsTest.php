<?php

namespace Flatrate\PublicCounts\Tests\Integration;

use Flatrate\PublicCounts\PublicVisibleWeekCounts;
use Flarum\Testing\integration\TestCase;

/**
 * These rows are synthetic. Counts use Discussion::whereVisibleTo and
 * Post::whereVisibleTo on a disposable Flarum 1.8 install with tags and approval.
 * A later hide or retag changes the count because the metric is current visibility.
 */
class PublicVisibleWeekCountsTest extends TestCase
{
    private const START = '2026-01-12T00:00:00.000Z';

    private const END = '2026-01-19T00:00:00.000Z';

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flatrate-public-counts', 'flarum-tags', 'flarum-flags', 'flarum-approval');
        $this->prepareDatabase([
            'group_permission' => [
                ['group_id' => 2, 'permission' => 'viewForum'],
            ],
            'tags' => [
                $this->tag(1, 'Public', null, 0),
                $this->tag(2, 'Restricted', null, 1),
                $this->tag(3, 'Child of restricted', 2, 0),
                $this->tag(4, 'Moderator', null, 1),
            ],
        ]);
    }

    public function test_public_discussion_and_reply_are_counted_without_opening_or_event_posts(): void
    {
        $this->prepareDatabase([
            'discussions' => [$this->discussion(10, '2026-01-13 12:00:00', ['comment_count' => 2])],
            'posts' => [
                $this->post(100, 10, 1, 'comment', '2026-01-13 12:00:00'),
                $this->post(101, 10, 2, 'comment', '2026-01-14 12:00:00'),
                $this->post(102, 10, null, 'discussionRenamed', '2026-01-14 13:00:00'),
            ],
            'discussion_tag' => [
                ['discussion_id' => 10, 'tag_id' => 1],
            ],
        ]);

        $result = $this->counts();

        $this->assertSame(1, $result['discussions']);
        $this->assertSame(1, $result['replies']);
        $this->assertFalse($result['snapshot']['truncated']);
        $this->assertSame('current_guest_visible_by_creation_time', $result['snapshot']['observation']);
    }

    public function test_boundaries_and_inaccessible_rows_are_excluded(): void
    {
        // Discussion 24 stays visible. Reply 200 stays visible because its parent
        // discussion is public even though that discussion was created before the week.
        $this->prepareDatabase([
            'discussions' => [
                $this->discussion(20, '2026-01-11 23:00:00'),
                $this->discussion(21, '2026-01-19 00:00:00'),
                $this->discussion(22, '2026-01-15 00:00:00', ['hidden_at' => '2026-01-16 00:00:00']),
                $this->discussion(23, '2026-01-15 00:00:00', ['is_private' => 1, 'is_approved' => 0]),
                $this->discussion(24, '2026-01-15 00:00:00'),
                $this->discussion(25, '2026-01-15 00:00:00'),
                $this->discussion(26, '2026-01-15 00:00:00'),
                $this->discussion(27, '2026-01-15 00:00:00'),
            ],
            'posts' => [
                $this->post(200, 20, 2, 'comment', '2026-01-13 00:00:00'),
                $this->post(201, 21, 1, 'comment', '2026-01-13 00:00:00'),
                $this->post(202, 22, 2, 'comment', '2026-01-15 01:00:00'),
                $this->post(203, 23, 2, 'comment', '2026-01-15 01:00:00'),
                $this->post(204, 24, 2, 'comment', '2026-01-15 01:00:00', ['hidden_at' => '2026-01-15 02:00:00']),
                $this->post(205, 24, 3, 'comment', '2026-01-15 03:00:00', ['is_private' => 1, 'is_approved' => 0]),
                $this->post(206, 24, 4, 'comment', '2026-01-19 00:00:00'),
                $this->post(207, 25, 2, 'comment', '2026-01-15 01:00:00'),
                $this->post(208, 26, 2, 'comment', '2026-01-15 01:00:00'),
                $this->post(209, 27, 2, 'comment', '2026-01-15 01:00:00'),
            ],
            'discussion_tag' => [
                ['discussion_id' => 20, 'tag_id' => 1],
                ['discussion_id' => 21, 'tag_id' => 1],
                ['discussion_id' => 22, 'tag_id' => 1],
                ['discussion_id' => 23, 'tag_id' => 1],
                ['discussion_id' => 24, 'tag_id' => 1],
                ['discussion_id' => 25, 'tag_id' => 2],
                ['discussion_id' => 26, 'tag_id' => 3],
                ['discussion_id' => 27, 'tag_id' => 1],
                ['discussion_id' => 27, 'tag_id' => 2],
            ],
        ]);

        $result = $this->counts();

        $this->assertSame(1, $result['discussions']);
        $this->assertSame(1, $result['replies']);
    }

    public function test_hiding_or_retagging_changes_the_current_visible_count(): void
    {
        $this->prepareDatabase([
            'discussions' => [$this->discussion(30, '2026-01-13 00:00:00', ['comment_count' => 2])],
            'posts' => [
                $this->post(300, 30, 1, 'comment', '2026-01-13 00:00:00'),
                $this->post(301, 30, 2, 'comment', '2026-01-13 01:00:00'),
            ],
            'discussion_tag' => [
                ['discussion_id' => 30, 'tag_id' => 1],
            ],
        ]);

        $this->assertSame(1, $this->counts()['discussions']);
        $this->assertSame(1, $this->counts()['replies']);

        $this->database()->table('discussions')->where('id', 30)->update(['hidden_at' => '2026-01-18 00:00:00']);
        $this->assertSame(0, $this->counts()['discussions']);
        $this->assertSame(0, $this->counts()['replies']);

        $this->database()->table('discussions')->where('id', 30)->update(['hidden_at' => null]);
        $this->database()->table('discussion_tag')->where('discussion_id', 30)->update(['tag_id' => 4]);
        $this->assertSame(0, $this->counts()['discussions']);

        $this->database()->table('discussion_tag')->where('discussion_id', 30)->update(['tag_id' => 1]);
        $this->database()->table('discussions')->where('id', 30)->update(['is_approved' => 0, 'is_private' => 1]);
        $this->assertSame(0, $this->counts()['discussions']);

        $this->database()->table('discussions')->where('id', 30)->update(['is_approved' => 1, 'is_private' => 0]);
        $this->assertSame(1, $this->counts()['discussions']);
    }

    public function test_read_committed_caller_is_rejected(): void
    {
        $db = $this->database();
        $db->rollBack();
        $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();
        try {
            (new PublicVisibleWeekCounts($db))->count('2026-01-12T00:00:00.000Z', '2026-01-19T00:00:00.000Z');
            $this->fail('A read-committed caller must not receive a verified count.');
        } catch (\RuntimeException $error) {
            $this->assertSame('inconsistent_snapshot', $error->getMessage());
        } finally {
            $db->rollBack();
            $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
        }
    }

    public function test_concurrent_hide_does_not_split_the_count_pair(): void
    {
        $db = $this->database();
        $db->rollBack();
        $db->beginTransaction();
        $db->table('group_permission')->updateOrInsert(
            ['group_id' => 2, 'permission' => 'viewForum'],
            ['group_id' => 2, 'permission' => 'viewForum']
        );
        $db->table('tags')->updateOrInsert(['id' => 1], $this->tag(1, 'Public', null, 0));
        $db->table('discussions')->insert($this->discussion(50, '2026-01-13 00:00:00', ['comment_count' => 2]));
        $db->table('posts')->insert($this->post(500, 50, 1, 'comment', '2026-01-13 00:00:00'));
        $db->table('posts')->insert($this->post(501, 50, 2, 'comment', '2026-01-13 01:00:00'));
        $db->table('discussion_tag')->insert(['discussion_id' => 50, 'tag_id' => 1]);
        $db->commit();

        $config = $db->getConfig();
        $reader = new PublicVisibleWeekCounts($db);
        $reader->betweenCounts = function () use ($config) {
            $pdo = new \PDO(
                'mysql:host='.$config['host'].';port='.($config['port'] ?? 3306).';dbname='.$config['database'],
                $config['username'],
                $config['password']
            );
            $pdo->exec("UPDATE discussions SET hidden_at = '2026-01-18 00:00:00' WHERE id = 50");
        };
        try {
            $db->beginTransaction();
            $result = $reader->count('2026-01-12T00:00:00.000Z', '2026-01-19T00:00:00.000Z');
            $this->assertSame($result['discussions'] > 0, $result['replies'] > 0);
            $this->assertSame(1, $result['discussions']);
            $this->assertSame(1, $result['replies']);
        } finally {
            $db->rollBack();
            $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $db->beginTransaction();
            $db->table('posts')->where('discussion_id', 50)->delete();
            $db->table('discussion_tag')->where('discussion_id', 50)->delete();
            $db->table('discussions')->where('id', 50)->delete();
            $db->commit();
            $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
        }
    }

    public function test_empty_public_week_is_a_qualified_zero(): void
    {
        $this->prepareDatabase([
            'discussions' => [$this->discussion(40, '2025-01-01 00:00:00')],
            'posts' => [$this->post(400, 40, 1, 'comment', '2025-01-01 00:00:00')],
            'discussion_tag' => [['discussion_id' => 40, 'tag_id' => 1]],
        ]);

        $result = $this->counts();

        $this->assertSame(0, $result['discussions']);
        $this->assertSame(0, $result['replies']);
        $this->assertFalse($result['snapshot']['truncated']);
        $this->assertGreaterThanOrEqual($result['snapshot']['window_end_utc'], $result['snapshot']['watermark_utc']);
    }

    public function test_explain_guest_count_queries(): void
    {
        $this->prepareDatabase([
            'discussions' => [$this->discussion(60, '2026-01-13 00:00:00')],
            'posts' => [$this->post(600, 60, 2, 'comment', '2026-01-13 01:00:00')],
            'discussion_tag' => [['discussion_id' => 60, 'tag_id' => 1]],
        ]);
        $this->database();
        $guest = new \Flarum\User\Guest();
        $discussions = \Flarum\Discussion\Discussion::query()->whereVisibleTo($guest)
            ->where('discussions.created_at', '>=', '2026-01-12 00:00:00')
            ->where('discussions.created_at', '<', '2026-01-19 00:00:00');
        $replies = \Flarum\Post\Post::query()->whereVisibleTo($guest)
            ->where('posts.type', 'comment')
            ->where('posts.number', '>', 1)
            ->where('posts.created_at', '>=', '2026-01-12 00:00:00')
            ->where('posts.created_at', '<', '2026-01-19 00:00:00');
        $discussionPlan = $this->database()->select('EXPLAIN '.$discussions->toSql(), $discussions->getBindings());
        $replyPlan = $this->database()->select('EXPLAIN '.$replies->toSql(), $replies->getBindings());
        file_put_contents('/tmp/flarum-r5-explain.json', json_encode([
            'discussions' => $discussionPlan,
            'replies' => $replyPlan,
        ], JSON_PRETTY_PRINT));
        $this->assertNotEmpty($discussionPlan);
        $this->assertNotEmpty($replyPlan);
    }

    private function counts(): array
    {
        return (new PublicVisibleWeekCounts($this->database()))->count(self::START, self::END);
    }

    private function tag(int $id, string $name, ?int $parent, int $restricted): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => 'tag-'.$id,
            'description' => '',
            'color' => null,
            'icon' => null,
            'position' => $id,
            'parent_id' => $parent,
            'is_restricted' => $restricted,
            'is_hidden' => 0,
            'discussion_count' => 0,
        ];
    }

    private function discussion(int $id, string $createdAt, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => 'Synthetic '.$id,
            'comment_count' => 1,
            'participant_count' => 1,
            'post_number_index' => 1,
            'created_at' => $createdAt,
            'user_id' => 1,
            'first_post_id' => null,
            'last_posted_at' => $createdAt,
            'last_posted_user_id' => 1,
            'last_post_number' => 1,
            'hidden_at' => null,
            'hidden_user_id' => null,
            'slug' => 'synthetic-'.$id,
            'is_private' => 0,
            'is_approved' => 1,
        ], $extra);
    }

    private function post(int $id, int $discussionId, ?int $number, string $type, string $createdAt, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'discussion_id' => $discussionId,
            'number' => $number,
            'created_at' => $createdAt,
            'user_id' => 1,
            'type' => $type,
            'content' => '<t><p>synthetic</p></t>',
            'edited_at' => null,
            'edited_user_id' => null,
            'hidden_at' => null,
            'hidden_user_id' => null,
            'ip_address' => null,
            'is_private' => 0,
            'is_approved' => 1,
        ], $extra);
    }
}
