<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\ProbeVisit;
use ReBuji\Install\WebSide;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class ProbeTest extends TestCase
{
    #[Test]
    public function answersTheWebSideToTheConsole(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $nonce = str_repeat('ab', 16);
            file_put_contents($root . '/storage/install-probe.txt', $nonce);
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . $nonce . '?from=console'));
            $web = WebSide::fromAnswer((string) $response->getBody(), $nonce);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertNotNull($web);
            $this->assertContains('sqlite', $web->drivers);
            $this->assertTrue($web->storageWritable);
            $this->assertNull($web->packagePath);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheInstallersAnswer(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $nonce = str_repeat('ab', 16);
            file_put_contents($root . '/storage/install-probe.txt', $nonce);
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . $nonce . '?from=other'));

            $this->assertSame($nonce, (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function recordsABrowsersVisit(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $visit = new ProbeVisit(new Paths($root));
            $nonce = $visit->open();
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . $nonce . '?from=browser'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('SSH の画面に戻ってください', (string) $response->getBody());
            $this->assertInstanceOf(WebSide::class, $visit->seen($nonce));
            $this->assertNull($visit->seen(str_repeat('cd', 16)));

            $visit->close();

            $this->assertFileDoesNotExist($root . '/storage/install-probe.txt');
            $this->assertFileDoesNotExist($root . '/storage/install-probe-visit.json');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function recordsNothingForAnotherNonce(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            (new ProbeVisit(new Paths($root)))->open();
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . str_repeat('cd', 16) . '?from=browser'));

            $this->assertSame(404, $response->getStatusCode());
            $this->assertFileDoesNotExist($root . '/storage/install-probe-visit.json');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhenItCannotRecordTheVisit(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $nonce = (new ProbeVisit(new Paths($root)))->open();
            chmod($root . '/storage', 0500);
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . $nonce . '?from=browser'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('storage/ に書けないため', (string) $response->getBody());
        } finally {
            chmod($root . '/storage', 0700);
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function readsOnlyAnAnswerForItsNonce(): void
    {
        $web = new WebSide('/re-buji', ['mysql'], false);

        $this->assertEquals($web, WebSide::fromAnswer($web->answer('n1'), 'n1'));
        $this->assertNull(WebSide::fromAnswer($web->answer('n1'), 'n2'));
        $this->assertNull(WebSide::fromAnswer('n1', 'n1'));
        $this->assertNull(WebSide::fromAnswer('{"nonce":"n1","package_path":1,"drivers":[],"storage_writable":true}', 'n1'));
        $this->assertNull(WebSide::fromAnswer('{"nonce":"n1","package_path":null,"drivers":"sqlite","storage_writable":true}', 'n1'));
    }

    #[Test]
    public function detectsWhereThePackageIsServed(): void
    {
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/public');
            touch($root . '/public/index.php');
            $server = ['SCRIPT_FILENAME' => $root . '/public/index.php', 'SCRIPT_NAME' => '/re-buji/public/index.php'];

            $this->assertSame('/re-buji', WebSide::detect($server, new Paths($root))->packagePath);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
