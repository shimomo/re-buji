<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * A temporary or policy answer to RCPT TO. Like RecipientRefusedResponse, it stays an UnexpectedResponseException so the
 * connection is kept for the next mail.
 *
 * @author shimomo
 *
 * @internal
 */
final class RecipientDeferredResponse extends UnexpectedResponseException
{
    //
}
