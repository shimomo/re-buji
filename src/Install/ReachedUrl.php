<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * The public URL that reached this copy, and what the web side of it sees.
 *
 * @author shimomo
 */
final readonly class ReachedUrl
{
    /**
     * @param string $url where this copy answers, without a / at the end; the one a redirect led to, if one was followed
     * @param bool $byBrowser whether the server could not reach it and the owner's browser opened it instead
     */
    public function __construct(public string $url, public WebSide $web, public bool $byBrowser)
    {
        //
    }
}
