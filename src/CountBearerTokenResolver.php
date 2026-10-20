<?php

namespace Flatrate\PublicCounts;

/**
 * Resolves the count-endpoint bearer token with env / optional explicit-file /
 * canonical /data fallback (same PikaPods pattern as Live Chat Centrifugo secrets).
 * Never logs or returns the token value.
 */
final class CountBearerTokenResolver
{
    public const ENV_TOKEN = 'FLATRATE_PUBLIC_COUNTS_TOKEN';

    public const ENV_TOKEN_FILE = 'FLATRATE_PUBLIC_COUNTS_TOKEN_FILE';

    public const DEFAULT_SECRET_DIRECTORY = '/data/flatrate-public-counts/secrets';

    public const TOKEN_FILENAME = 'count-bearer-token';

    public const MAX_TOKEN_BYTES = 512;

    public const SOURCE_ENV = 'env-value';

    public const SOURCE_EXPLICIT_FILE = 'explicit-file';

    public const SOURCE_DEFAULT_FILE = 'default-file';

    public const SOURCE_MISSING = 'missing';

    public const SOURCE_INVALID = 'invalid';

    public function __construct(
        private string $defaultSecretDirectory = self::DEFAULT_SECRET_DIRECTORY
    ) {
    }

    public function defaultSecretDirectory(): string
    {
        return $this->defaultSecretDirectory;
    }

    public function canonicalSecretPath(): string
    {
        return rtrim($this->defaultSecretDirectory, '/').'/'.self::TOKEN_FILENAME;
    }

    /**
     * @return array{value: ?string, source: string}
     */
    public function resolveDetailed(): array
    {
        $direct = self::env(self::ENV_TOKEN);
        if ($direct !== null) {
            $normalized = $this->normalizeToken($direct);
            if ($normalized === null) {
                return ['value' => null, 'source' => self::SOURCE_INVALID];
            }

            return ['value' => $normalized, 'source' => self::SOURCE_ENV];
        }

        $explicitPath = self::env(self::ENV_TOKEN_FILE);
        if ($explicitPath !== null) {
            $loaded = $this->readSecretFile($explicitPath);
            if ($loaded === null) {
                return ['value' => null, 'source' => self::SOURCE_INVALID];
            }

            return ['value' => $loaded, 'source' => self::SOURCE_EXPLICIT_FILE];
        }

        $loaded = $this->readSecretFile($this->canonicalSecretPath());
        if ($loaded === null) {
            // Distinguish missing vs invalid when the path exists as a bad file.
            if (@is_file($this->canonicalSecretPath()) || @is_link($this->canonicalSecretPath())) {
                return ['value' => null, 'source' => self::SOURCE_INVALID];
            }

            return ['value' => null, 'source' => self::SOURCE_MISSING];
        }

        return ['value' => $loaded, 'source' => self::SOURCE_DEFAULT_FILE];
    }

    public function resolve(): ?string
    {
        return $this->resolveDetailed()['value'];
    }

    private function readSecretFile(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        if (@is_link($path)) {
            return null;
        }
        if (! @is_file($path) || @is_dir($path)) {
            return null;
        }
        if (! @is_readable($path)) {
            return null;
        }

        // Prefer non-world-readable material when the filesystem reports mode.
        $perms = @fileperms($path);
        if ($perms !== false && ($perms & 0004) === 0004) {
            return null;
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_TOKEN_BYTES) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (strlen($raw) > self::MAX_TOKEN_BYTES) {
            return null;
        }

        return $this->normalizeToken($raw);
    }

    private function normalizeToken(string $raw): ?string
    {
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }

        $value = preg_replace('/\A\s+/', '', $raw) ?? $raw;
        $value = preg_replace('/\r?\n\z/', '', $value) ?? $value;
        $value = rtrim($value, " \t");

        if ($value === '') {
            return null;
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }
        // Reject control characters other than already-trimmed whitespace.
        if (preg_match('/[[:cntrl:]]/', $value) === 1) {
            return null;
        }
        if (strlen($value) > self::MAX_TOKEN_BYTES) {
            return null;
        }
        // Reject internal whitespace ambiguity.
        if (preg_match('/\s/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function env(string $key): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }
}
