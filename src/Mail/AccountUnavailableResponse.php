<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A failure before the log-in was through, wherever symfony/mailer connected (a first mail, or a reconnection in the
 * middle of a run).
 *
 * @author shimomo
 *
 * @internal
 */
final class AccountUnavailableResponse extends TransportException
{
    //
}
