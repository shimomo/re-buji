<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Url;
use ReBuji\SystemState;

/**
 * Clears the feed warnings on the home page once an admin has looked into them: a gap jmaxml/feed could not fill and
 * telegrams it gave up on stay there until then.
 *
 * @author shimomo
 */
final class FeedNoticeController
{
    public function __construct(private readonly SystemState $state, private readonly ResponseFactoryInterface $responses, private readonly Url $url)
    {
        //
    }

    public function acknowledge(ServerRequestInterface $request): ResponseInterface
    {
        $this->state->set('feed.gap_since', '');
        $this->state->set('feed.failed_telegrams', '');

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/'));
    }
}
