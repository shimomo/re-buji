<?php

declare(strict_types=1);

namespace ReBuji\Mail;

/**
 * What happened to one person's mails in a run.
 *
 * @author shimomo
 *
 * @internal
 */
enum Delivery
{
    /**
     * At least one address got the mail.
     */
    case Sent;

    /**
     * Every address was refused, or there was none to send to.
     */
    case Refused;

    /**
     * The server could not be used before anything went out: try again next run.
     */
    case Unavailable;

    /**
     * Not tried: the hourly limit or the run's time is used up.
     */
    case Postponed;
}
