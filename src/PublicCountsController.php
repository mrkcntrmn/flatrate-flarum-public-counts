<?php

namespace Flatrate\PublicCounts;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class PublicCountsController implements RequestHandlerInterface
{
    public function __construct(private PublicVisibleWeekCounts $counts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) getenv('FLATRATE_PUBLIC_COUNTS_TOKEN');
        $header = $request->getHeaderLine('Authorization');
        if ($token === '' || ! hash_equals('Bearer '.$token, $header)) {
            return new JsonResponse(['error' => 'SOURCE_UNAUTHORIZED'], 401);
        }
        if ($request->getQueryParams() !== []) {
            return new JsonResponse(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
        }
        $body = $request->getParsedBody();
        if (! is_array($body) || array_diff(array_keys($body), ['startUtc', 'endUtc']) !== []) {
            return new JsonResponse(['error' => 'SOURCE_RESPONSE_INVALID'], 400);
        }
        try {
            $result = $this->counts->count((string) ($body['startUtc'] ?? ''), (string) ($body['endUtc'] ?? ''));
        } catch (CountRejected $error) {
            return new JsonResponse(['error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            return new JsonResponse(['error' => 'SOURCE_UNAVAILABLE'], 503);
        }

        return new JsonResponse([
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
        ]);
    }
}
