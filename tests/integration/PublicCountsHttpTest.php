<?php

namespace Flatrate\PublicCounts\Tests\Integration;

use Flarum\Extend\ExtenderInterface;
use Flarum\Testing\integration\TestCase;
use Flatrate\PublicCounts\PublicVisibleWeekCounts;
use RuntimeException;

class PublicCountsHttpTest extends TestCase
{
    private const TOKEN = 'disposable-test-token';

    private const START = '2026-01-12T00:00:00.000Z';

    private const END = '2026-01-19T00:00:00.000Z';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN='.self::TOKEN);
        $this->extension('flatrate-public-counts', 'flarum-tags', 'flarum-flags', 'flarum-approval');
    }

    protected function tearDown(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        parent::tearDown();
    }

    public function test_missing_wrong_and_empty_tokens_do_not_count(): void
    {
        foreach ([null, '', 'Bearer ', 'Bearer wrong-token', 'bearer '.self::TOKEN] as $header) {
            $request = $this->request('POST', '/api/flatrate/public-counts', [
                'json' => ['startUtc' => self::START, 'endUtc' => self::END],
            ]);
            if ($header !== null) {
                $request = $request->withHeader('Authorization', $header);
            }
            $this->database()->flushQueryLog();
            $this->database()->enableQueryLog();
            $response = $this->send($request);
            $this->assertSame(401, $response->getStatusCode());
            $this->assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertNoCountQueries();
        }
    }

    public function test_missing_server_token_fails_closed(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        $response = $this->send($this->authorized([
            'startUtc' => self::START,
            'endUtc' => self::END,
        ]));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_valid_token_returns_only_the_aggregate(): void
    {
        $this->prepareDatabase([
            'group_permission' => [
                ['group_id' => 2, 'permission' => 'viewForum'],
            ],
            'discussions' => [[
                'id' => 10,
                'title' => 'Synthetic',
                'comment_count' => 1,
                'participant_count' => 1,
                'post_number_index' => 1,
                'created_at' => '2026-01-13 00:00:00',
                'user_id' => 1,
                'last_posted_at' => '2026-01-13 00:00:00',
                'last_posted_user_id' => 1,
                'last_post_number' => 1,
                'slug' => 'synthetic-10',
                'is_private' => 0,
                'is_approved' => 1,
            ]],
            'posts' => [[
                'id' => 100,
                'discussion_id' => 10,
                'number' => 1,
                'created_at' => '2026-01-13 00:00:00',
                'user_id' => 1,
                'type' => 'comment',
                'content' => '<t><p>synthetic</p></t>',
                'is_private' => 0,
                'is_approved' => 1,
            ]],
        ]);

        $response = $this->send($this->authorized([
            'startUtc' => self::START,
            'endUtc' => self::END,
        ]));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['discussions', 'replies', 'snapshot'], array_keys($payload));
        $this->assertSame(1, $payload['discussions']);
        $this->assertSame(0, $payload['replies']);
        $encoded = json_encode($payload);
        foreach (['user_id', 'discussion_id', 'post_id', 'username', 'email', 'synthetic'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_malformed_input_is_rejected_before_a_count(): void
    {
        $cases = [
            [['startUtc' => self::START], 400],
            [['startUtc' => self::START, 'endUtc' => self::END, 'user_id' => 1], 400],
            [['startUtc' => ['nested' => true], 'endUtc' => self::END], 400],
            [['startUtc' => '2026-01-12T00:00:00+00:00', 'endUtc' => self::END], 422],
            [['startUtc' => '2026-01-12T00:00:00.000Z', 'endUtc' => '2026-01-13T00:00:00.000Z'], 422],
            [['startUtc' => '2026-10-26T00:00:00.000Z', 'endUtc' => '2026-11-02T00:00:00.000Z'], 422],
        ];
        foreach ($cases as [$json, $status]) {
            $this->database()->flushQueryLog();
            $this->database()->enableQueryLog();
            $response = $this->send($this->authorized($json));
            $this->assertSame($status, $response->getStatusCode(), json_encode($json));
            if ($status === 400) {
                $this->assertNoCountQueries();
            }
        }
    }

    public function test_get_is_not_permitted(): void
    {
        $response = $this->send($this->request('GET', '/api/flatrate/public-counts')->withHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertContains($response->getStatusCode(), [404, 405]);
    }

    public function test_exception_response_is_sanitized(): void
    {
        $this->extend(new class implements ExtenderInterface {
            public function extend($container, $extension = null)
            {
                $container->resolving(PublicVisibleWeekCounts::class, function (PublicVisibleWeekCounts $counts) {
                    $counts->betweenCounts = function () {
                        throw new RuntimeException('SELECT secret_token FROM users');
                    };
                });
            }
        });
        $response = $this->send($this->authorized([
            'startUtc' => self::START,
            'endUtc' => self::END,
        ]));
        $raw = (string) $response->getBody();
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('secret_token', $raw);
        $this->assertStringNotContainsString('SELECT', $raw);
        $this->assertStringContainsString('SOURCE_UNAVAILABLE', $raw);
    }


    public function test_protected_file_token_authorizes_without_env(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        $dir = sys_get_temp_dir().'/fr-public-counts-http-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $path = $dir.'/count-bearer-token';
        file_put_contents($path, self::TOKEN."\n");
        chmod($path, 0600);
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE='.$path);
        try {
            $this->database()->flushQueryLog();
            $this->database()->enableQueryLog();
            $response = $this->send($this->authorized([
                'startUtc' => self::START,
                'endUtc' => self::END,
            ]));
            // Without fixtures this may 200 with zeros or still authorize past 401.
            $this->assertNotSame(401, $response->getStatusCode());
            $this->assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        } finally {
            putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE');
            @unlink($path);
            @rmdir($dir);
            putenv('FLATRATE_PUBLIC_COUNTS_TOKEN='.self::TOKEN);
        }
    }

    public function test_symlink_file_token_is_unauthorized(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        $dir = sys_get_temp_dir().'/fr-public-counts-http-link-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $real = $dir.'/real';
        $link = $dir.'/count-bearer-token';
        file_put_contents($real, self::TOKEN."\n");
        chmod($real, 0600);
        symlink($real, $link);
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE='.$link);
        try {
            $this->database()->flushQueryLog();
            $this->database()->enableQueryLog();
            $response = $this->send($this->authorized([
                'startUtc' => self::START,
                'endUtc' => self::END,
            ]));
            $this->assertSame(401, $response->getStatusCode());
            $this->assertNoCountQueries();
            $this->assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        } finally {
            putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE');
            @unlink($link);
            @unlink($real);
            @rmdir($dir);
            putenv('FLATRATE_PUBLIC_COUNTS_TOKEN='.self::TOKEN);
        }
    }

    private function authorized(array $json)
    {
        return $this->request('POST', '/api/flatrate/public-counts', ['json' => $json])
            ->withHeader('Authorization', 'Bearer '.self::TOKEN);
    }

    private function assertNoCountQueries(): void
    {
        foreach ($this->database()->getQueryLog() as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('from `discussions`', $sql);
            $this->assertStringNotContainsString('from `posts`', $sql);
        }
    }
}
