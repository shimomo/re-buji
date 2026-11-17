<?php

declare(strict_types=1);

namespace ReBuji\Database;

use RuntimeException;

/**
 * Another request is applying the database changes (the update button pressed twice, say).
 *
 * @author shimomo
 */
final class MigrationInProgress extends RuntimeException
{
    //
}
