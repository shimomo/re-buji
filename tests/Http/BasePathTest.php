<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Http\BasePath;

/**
 * @author shimomo
 */
final class BasePathTest extends TestCase
{
    /**
     * @param array<string, string> $server
     */
    #[Test]
    #[DataProvider('layouts')]
    public function detectsWhereTheAppIsServedFrom(array $server, string $expected): void
    {
        $this->assertSame($expected, BasePath::detect($server));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function layouts(): iterable
    {
        yield 'document root is public/' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/login'], ''];
        yield 'whole package in a subdirectory' => [['SCRIPT_NAME' => '/re-buji/public/index.php', 'REQUEST_URI' => '/re-buji/login'], '/re-buji'];
        yield 'whole package at the document root' => [['SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/login'], ''];
        yield 'public/ itself requested' => [['SCRIPT_NAME' => '/re-buji/public/index.php', 'REQUEST_URI' => '/re-buji/public/login'], '/re-buji/public'];
        yield 'nothing known' => [[], ''];
    }
}
