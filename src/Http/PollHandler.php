<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Http;

use LPhenom\Http\HandlerInterface;
use LPhenom\Http\Request;
use LPhenom\Http\Response;
use LPhenom\Realtime\RealtimeBusInterface;

/**
 * HTTP handler for the long-polling endpoint.
 *
 * Returns JSON with all messages newer than the given sinceId.
 * Clients poll this endpoint periodically and pass the last received
 * message id as the "since" query parameter.
 *
 * Request:  GET /realtime/poll?topic=chat&since=42&limit=100
 * Response: {"messages":[...], "last_id": 45}
 *
 * Note: This handler is PHP-only (not included in the KPHP entrypoint)
 * because lphenom/http itself uses trailing commas (PHP 8.0+ syntax).
 * Use DbEventStoreBus / WebSocketBus directly in KPHP compiled mode.
 *
 * @lphenom-build shared
 */
final class PollHandler implements HandlerInterface
{
    /** @var RealtimeBusInterface */
    private RealtimeBusInterface $bus;
    public function __construct(RealtimeBusInterface $bus)
    {
        $this->bus = $bus;
    }
    public function handle(Request $request): Response
    {
        $query = $request->getQuery();
        $topicRaw = $query['topic'] ?? '';
        $topic    = (string) $topicRaw;
        if ($topic === '') {
            return Response::json(['error' => 'topic parameter is required'], 400);
        }
        $sinceRaw = $query['since'] ?? '0';
        $sinceId  = (int) $sinceRaw;
        $limitRaw = $query['limit'] ?? '100';
        $limit    = (int) $limitRaw;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 500) {
            $limit = 500;
        }
        $messages = $this->bus->readSince($topic, $sinceId, $limit);
        $items  = [];
        $lastId = $sinceId;
        foreach ($messages as $msg) {
            $item            = [];
            $item['id']      = $msg->getId();
            $item['topic']   = $msg->getTopic();
            $item['payload'] = $msg->getPayloadJson();
            $item['ts']      = $msg->getCreatedAt()->format('Y-m-d H:i:s');
            $items[]         = $item;
            if ($msg->getId() > $lastId) {
                $lastId = $msg->getId();
            }
        }
        return Response::json(['messages' => $items, 'last_id' => $lastId]);
    }
}
