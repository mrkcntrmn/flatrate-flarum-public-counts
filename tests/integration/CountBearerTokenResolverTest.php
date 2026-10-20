<?php

namespace Flatrate\PublicCounts\Tests\Integration;

use Flatrate\PublicCounts\CountBearerTokenResolver;
use PHPUnit\Framework\TestCase;

class CountBearerTokenResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE');
        unset($_SERVER['FLATRATE_PUBLIC_COUNTS_TOKEN'], $_ENV['FLATRATE_PUBLIC_COUNTS_TOKEN']);
        unset($_SERVER['FLATRATE_PUBLIC_COUNTS_TOKEN_FILE'], $_ENV['FLATRATE_PUBLIC_COUNTS_TOKEN_FILE']);
        $this->dir = sys_get_temp_dir().'/fr-public-counts-secrets-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE');
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_env_token_wins(): void
    {
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN=env-token-value');
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, "file-token-value\n");
        chmod($path, 0600);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertSame('env-token-value', $detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_ENV, $detail['source']);
    }

    public function test_canonical_file_token(): void
    {
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, "file-token-value\n");
        chmod($path, 0600);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertSame('file-token-value', $detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_DEFAULT_FILE, $detail['source']);
    }

    public function test_explicit_file_env(): void
    {
        $path = $this->dir.'/alt-token';
        file_put_contents($path, "alt-token-value\n");
        chmod($path, 0600);
        putenv('FLATRATE_PUBLIC_COUNTS_TOKEN_FILE='.$path);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertSame('alt-token-value', $detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_EXPLICIT_FILE, $detail['source']);
    }

    public function test_missing_token(): void
    {
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertNull($detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_MISSING, $detail['source']);
    }

    public function test_empty_file_rejected(): void
    {
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, "");
        chmod($path, 0600);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertNull($detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_INVALID, $detail['source']);
    }

    public function test_symlink_rejected(): void
    {
        $real = $this->dir.'/real-token';
        file_put_contents($real, "symlink-target-token\n");
        chmod($real, 0600);
        $link = $this->dir.'/count-bearer-token';
        symlink($real, $link);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertNull($detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_INVALID, $detail['source']);
    }

    public function test_oversized_file_rejected(): void
    {
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, str_repeat('a', CountBearerTokenResolver::MAX_TOKEN_BYTES + 1));
        chmod($path, 0600);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertNull($detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_INVALID, $detail['source']);
    }

    public function test_world_readable_file_rejected(): void
    {
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, "world-readable-token\n");
        chmod($path, 0644);
        $resolver = new CountBearerTokenResolver($this->dir);
        $detail = $resolver->resolveDetailed();
        $this->assertNull($detail['value']);
        $this->assertSame(CountBearerTokenResolver::SOURCE_INVALID, $detail['source']);
    }

    public function test_whitespace_inside_token_rejected(): void
    {
        $path = $this->dir.'/count-bearer-token';
        file_put_contents($path, "bad token\n");
        chmod($path, 0600);
        $resolver = new CountBearerTokenResolver($this->dir);
        $this->assertNull($resolver->resolve());
    }
}
