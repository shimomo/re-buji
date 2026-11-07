<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Url;
use Slim\Exception\HttpNotFoundException;

/**
 * Mail that matched no contact (design 5.2, step 4): from an address nobody registered, or without our number.
 *
 * @author shimomo
 */
final class UnmatchedController
{
    private const array KINDS = ['reply' => '返信', 'auto_reply' => '自動応答', 'bounce' => '宛先不明', 'delayed' => '配信の遅れ', 'unreadable' => '読めないメール'];

    public function __construct(
        private readonly ReplyRepository $replies,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
    ) {
        //
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $mails = array_map(static fn(array $mail): array => [
            'id' => $mail['id'],
            'kind' => self::KINDS[$mail['kind']] ?? $mail['kind'],
            'from' => $mail['fromAddress'],
            'subject' => $mail['subject'],
            'excerpt' => mb_substr($mail['body'], 0, 200),
            'received' => $mail['receivedAt']->setTimezone($tokyo)->format('n月j日 G:i'),
        ], $this->replies->unmatched());

        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('mail/unmatched', [
            'title' => '照合できなかったメール',
            'admin' => $request->getAttribute('admin'),
            'mails' => $mails,
        ]));

        return $response;
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->replies->deleteUnmatched((int) ($args['id'] ?? '0'))) {
            throw new HttpNotFoundException($request);
        }

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/mail/unmatched'));
    }
}
