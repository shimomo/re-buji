<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use RuntimeException;

/**
 * The server, or the address itself, refused this recipient: retrying will not help. The contact records it as not delivered.
 *
 * @author shimomo
 */
final class RecipientRefused extends RuntimeException
{
    //
}
