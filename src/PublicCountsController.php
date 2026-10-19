<?php

namespace Flatrate\PublicCounts;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class PublicCountsController implements RequestHandlerInterface
{
    public const MAX_REQUEST_BYTES = 1024;

    public function __construct(private PublicVisibleWeekCounts $counts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) getenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        $header = $request->getHeaderLine('Authorization');
        if ($token === '' || ! hash_equals('Bearer '.$token, $header)) {
            return $this->json(['error' => 'SOURCE_UNAUTHORIZED'], 401);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 405);
        }
        try {
            if ($request->getQueryParams() !== []) {
                return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
            }
            if (! str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
                return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 415);
            }
            if ($this->bodyTooLarge($request)) {
                return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
            }
            $body = $request->getParsedBody();
            $keys = is_array($body) ? array_keys($body) : [];
            sort($keys);
            if (! is_array($body) || array_is_list($body) || $keys !== ['endUtc', 'startUtc']) {
                return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
            }
            if (! is_string($body['startUtc']) || ! is_string($body['endUtc'])) {
                return $this->json(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
            }
            $result = $this->counts->count($body['startUtc'], $body['endUtc']);
        } catch (CountRejected $error) {
            return $this->json(['error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return $this->json(['error' => 'SOURCE_UNAVAILABLE'], 503);
        }

        return $this->json([
            'discussions' => $result['discussions'],
            'replies' => $result['replies'],
            'snapshot' => [
                'source' => $result['snapshot']['source'],
                'visibility' => $result['snapshot']['visibility'],
                'count_semantics' => $result['snapshot']['count_semantics'],
                'window_start_utc' => $result['snapshot']['window_start_utc'],
                'window_end_utc' => $result['snapshot']['window_end_utc'],
                'watermark_utc' => $result['snapshot']['watermark_utc'],
                'truncated' => false,
                'discussions' => $result['discussions'],
                'replies' => $result['replies'],
                'ledger_is_forum_history' => false,
                'observation' => 'current_guest_visible_by_creation_time',
            ],
        ], 200);
    }

    private function bodyTooLarge(ServerRequestInterface $request): bool
    {
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && (! ctype_digit($declared) || (int) $declared > self::MAX_REQUEST_BYTES)) {
            return true;
        }
        $stream = $request->getBody();
        try {
            if ($stream->isSeekable() && $stream->isReadable()) {
                $size = $stream->getSize();
                if ($size !== null) {
                    return $size > self::MAX_REQUEST_BYTES;
                }
                $stream->rewind();
                $sample = $stream->read(self::MAX_REQUEST_BYTES + 1);
                $stream->rewind();

                return strlen($sample) > self::MAX_REQUEST_BYTES;
            }
        } catch (Throwable $error) {
            return true;
        }
        $encoded = json_encode($request->getParsedBody());

        return strlen((string) $encoded) > self::MAX_REQUEST_BYTES;
    }

    private function json(array $payload, int $status): ResponseInterface
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'no-store']);
    }
}
