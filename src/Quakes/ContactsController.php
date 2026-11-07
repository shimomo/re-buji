<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Url;
use Slim\Exception\HttpNotFoundException;

/**
 * What an admin does about one person's contact on the manager screen.
 *
 * @author shimomo
 */
final class ContactsController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    /**
     * "確認済みにする": an admin has read what the person wrote (design 5.6).
     *
     * @param array<string, string> $args
     */
    public function check(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $contact = $this->notifications->find((int) ($args['id'] ?? '0'));

        if ($contact === null || $contact->answer !== Notification::NEEDS_CHECK) {
            throw new HttpNotFoundException($request);
        }

        $this->notifications->markChecked($contact->id, self::adminName($request), $this->clock->now());

        return $this->backTo($contact);
    }

    private function backTo(Notification $contact): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes/' . $contact->earthquakeId));
    }

    private static function adminName(ServerRequestInterface $request): string
    {
        /** @var array{id: int, name: string, email: string} $admin */
        $admin = $request->getAttribute('admin');

        return $admin['name'];
    }
}
