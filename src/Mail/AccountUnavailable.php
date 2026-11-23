<?php

declare(strict_types=1);

namespace ReBuji\Mail;

/**
 * The sending account could not be used: no connection, no greeting, no TLS, or a failed log-in. Everything after the
 * log-in (a refused sender or body, a timeout after the body) stays a plain MailUnavailable: the account itself worked.
 *
 * @author shimomo
 */
final class AccountUnavailable extends MailUnavailable
{
    //
}
