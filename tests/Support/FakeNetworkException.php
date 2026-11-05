<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * @author shimomo
 */
final class FakeNetworkException extends RuntimeException implements ClientExceptionInterface
{
    //
}
