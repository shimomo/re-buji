<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use League\Plates\Engine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Http\Url;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use ReBuji\Version;

/**
 * @author shimomo
 */
final class LayoutTest extends TestCase
{
    #[Test]
    public function linksTheStylesheetUnderTheBasePathWithTheVersion(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $body = (string) $app->handle(TestApp::request('GET', '/install/environment'))->getBody();

            $this->assertStringContainsString('<link rel="stylesheet" href="/assets/app.css?v=' . Version::CURRENT . '">', $body);
            $this->assertStringNotContainsString('<style>', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function linksTheStylesheetUnderASubdirectory(): void
    {
        $root = TestApp::root();

        try {
            [$app] = TestApp::build($root, [Url::class => new Url('/re-buji')]);
            $body = (string) $app->handle(TestApp::request('GET', '/install/environment'))->getBody();

            $this->assertStringContainsString('href="/re-buji/assets/app.css?v=', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function shipsTheStylesheet(): void
    {
        $file = dirname(__DIR__, 2) . '/public/assets/app.css';

        $this->assertFileExists($file);
        $css = (string) file_get_contents($file);

        foreach (['#f4f6f8', '#ffffff', '#26323a', '#5f6b74', '#e3e7eb', '#2b6cb0', '#c0392b', '#9a5800', '#237356'] as $color) {
            $this->assertStringContainsString($color, $css);
        }
    }

    #[Test]
    public function marksTheBodyOnlyWhenThePageHasANav(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $this->assertStringContainsString('<body class="has-nav">', (string) $app->handle(TestApp::request('GET', '/employees'))->getBody());

            [$bareApp, , $bareRoot] = TestApp::create();

            try {
                $bare = (string) $bareApp->handle(TestApp::request('GET', '/install/environment'))->getBody();
                $this->assertStringContainsString('class="bare"', $bare);
                $this->assertStringNotContainsString('has-nav', $bare);
            } finally {
                TemporaryDirectory::remove($bareRoot);
            }
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function aBarePageKeepsTheBrandButDropsTheNav(): void
    {
        [, $container, $root] = TestApp::create();

        try {
            $engine = $container->get(Engine::class);
            $this->assertInstanceOf(Engine::class, $engine);
            $nav = ['title' => 'x', 'bare' => false];
            $this->assertStringNotContainsString('class="bare"', $engine->render('layout', $nav));

            $body = $engine->render('layout', ['title' => 'x', 'bare' => true]);

            $this->assertStringContainsString('<header><strong>Re:無事</strong>', $body);
            $this->assertStringContainsString('class="bare"', $body);
            $this->assertStringNotContainsString('<nav', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
