<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * How a request to the public URL's probe ended.
 *
 * @author shimomo
 */
enum ProbeOutcome
{
    case Reached;
    case Unreachable;
    case Refused;
    case Redirected;
}
