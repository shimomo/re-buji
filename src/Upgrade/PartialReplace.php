<?php

declare(strict_types=1);

namespace ReBuji\Upgrade;

use RuntimeException;

/**
 * Replacing the files failed and some of the old ones could not be put back: the copy is half old, half new.
 *
 * @author shimomo
 */
final class PartialReplace extends RuntimeException
{
}
