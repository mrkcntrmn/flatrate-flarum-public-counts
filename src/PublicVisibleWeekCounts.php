<?php

namespace Flatrate\PublicCounts;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Current guest-visible rows whose creation time falls in one closed UTC week.
 * This is not a reconstruction of what was visible at the historical week close.
     * Post::whereVisibleTo already requires the parent discussion to be visible
     * through Flarum 1.8 ScopePostVisibility. Discussion::whereVisibleTo applies
     * core, tags, and approval scopers registered on this install.
     *
     * The watermark is the UTC clock read inside the repeatable-read transaction
     * before either count. It is the source observation time, not a database
     * snapshot LSN and not MAX(created_at).
     */
final class PublicVisibleWeekCounts
{
    /**
     * Test seam. Runs after the discussion count and before the reply count,
     * inside the same transaction. Production leaves this null.
     */
    public ?Closure $betweenCounts = null;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function count(string $startUtc, string $endUtc): array
    {
        [$start, $end] = $this->window($startUtc, $endUtc);
        $this->requireRepeatableSnapshot();

        return $this->db->transaction(function () use ($start, $end) {
            $observedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($observedAt < $end) {
                throw new CountRejected('SOURCE_SNAPSHOT_INVALID');
            }
            $guest = new Guest();
            $discussions = Discussion::query()
                ->whereVisibleTo($guest)
                ->where('discussions.created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('discussions.created_at', '<', $end->format('Y-m-d H:i:s'))
                ->count();
            if ($this->betweenCounts) {
                ($this->betweenCounts)();
            }
            $replies = Post::query()
                ->whereVisibleTo($guest)
                ->where('posts.type', 'comment')
                ->where('posts.number', '>', 1)
                ->where('posts.created_at', '>=', $start->format('Y-m-d H:i:s'))
                ->where('posts.created_at', '<', $end->format('Y-m-d H:i:s'))
                ->count();

            return [
                'discussions' => $discussions,
                'replies' => $replies,
                'snapshot' => [
                    'source' => 'flarum_canonical_forum_aggregate_v1',
                    'visibility' => 'public_visible_excluding_hidden_moderated',
                    'count_semantics' => 'total_rows_not_distinct_authors',
                    'window_start_utc' => $start->format('Y-m-d\TH:i:s.000\Z'),
                    'window_end_utc' => $end->format('Y-m-d\TH:i:s.000\Z'),
                    'watermark_utc' => $observedAt->format('Y-m-d\TH:i:s.000\Z'),
                    'truncated' => false,
                    'discussions' => $discussions,
                    'replies' => $replies,
                    'ledger_is_forum_history' => false,
                    'observation' => 'current_guest_visible_by_creation_time',
                ],
            ];
        });
    }

    private function window(string $startUtc, string $endUtc): array
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/';
        if (! preg_match($pattern, $startUtc) || ! preg_match($pattern, $endUtc)) {
            throw new CountRejected('SOURCE_SNAPSHOT_INVALID');
        }
        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.000\Z', $startUtc, new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.000\Z', $endUtc, new DateTimeZone('UTC'));
        if (! $start || ! $end || $start->format('Y-m-d\TH:i:s.000\Z') !== $startUtc || $end->format('Y-m-d\TH:i:s.000\Z') !== $endUtc) {
            throw new CountRejected('SOURCE_SNAPSHOT_INVALID');
        }
        if ($end->getTimestamp() - $start->getTimestamp() !== 7 * 24 * 60 * 60) {
            throw new CountRejected('SOURCE_SNAPSHOT_INVALID');
        }

        return [$start, $end];
    }

    private function requireRepeatableSnapshot(): void
    {
        $driver = $this->db->getDriverName();
        if ($driver !== 'mysql') {
            throw new RuntimeException('unsupported_count_driver');
        }
        if ($this->db->transactionLevel() === 0) {
            $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return;
        }
        $level = '';
        try {
            $level = (string) ($this->db->selectOne('SELECT @@transaction_isolation AS lvl')->lvl ?? '');
        } catch (Throwable $error) {
            $level = (string) ($this->db->selectOne('SELECT @@tx_isolation AS lvl')->lvl ?? '');
        }
        $normalized = strtoupper(str_replace(' ', '-', $level));
        if (! in_array($normalized, ['REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new RuntimeException('inconsistent_snapshot');
        }
    }
}
