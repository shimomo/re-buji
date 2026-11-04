<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class InstalledGateTest extends TestCase
{
    #[Test]
    public function sendsEverythingToTheInstallerBeforeSetup(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $response = $app->handle(TestApp::request('GET', '/'));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/install', $response->getHeaderLine('Location'));

            $this->assertSame(200, $app->handle(TestApp::request('GET', '/install/environment'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function hidesTheInstallerAfterSetup(): void
    {
        [$app, , $root] = TestApp::create([], TestApp::installedConfig());

        try {
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/install'))->getStatusCode());

            $response = $app->handle(TestApp::request('GET', '/'));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/login', $response->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheBasePathInRedirects(): void
    {
        [$app, , $root] = TestApp::create(['basePath' => '/re-buji']);

        try {
            $app->setBasePath('/re-buji');
            $response = $app->handle(TestApp::request('GET', '/re-buji/'));

            $this->assertSame('/re-buji/install', $response->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
