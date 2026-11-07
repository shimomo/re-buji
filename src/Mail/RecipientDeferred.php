<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use RuntimeException;

/**
 * The server would not take this recipient for now: a temporary refusal (4xx), or a policy refusal (5.7.x, such as
 * relaying denied for an account set up without logging in). The person is tried again later; the others are not
 * held up.
 *
 * @author shimomo
 */
final class RecipientDeferred extends RuntimeException
{
    //
}
