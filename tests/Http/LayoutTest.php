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
    public function linksTheIconsWithTheVersion(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $body = (string) $app->handle(TestApp::request('GET', '/install/environment'))->getBody();
            $png = '<link rel="icon" href="/assets/favicon-32.png?v=' . Version::CURRENT . '" sizes="32x32" type="image/png">';
            $svg = '<link rel="icon" href="/assets/favicon.svg?v=' . Version::CURRENT . '" type="image/svg+xml">';

            $this->assertStringContainsString($png, $body);
            $this->assertStringContainsString($svg, $body);
            $this->assertStringContainsString('<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png?v=' . Version::CURRENT . '">', $body);
            // A browser that reads SVG icons takes the later one; any other keeps the PNG.
            $this->assertLessThan(strpos($body, $svg), strpos($body, $png));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function linksTheIconsUnderASubdirectory(): void
    {
        $root = TestApp::root();

        try {
            [$app] = TestApp::build($root, [Url::class => new Url('/re-buji')]);
            $body = (string) $app->handle(TestApp::request('GET', '/install/environment'))->getBody();

            $this->assertStringContainsString('href="/re-buji/assets/favicon-32.png?v=', $body);
            $this->assertStringContainsString('href="/re-buji/assets/favicon.svg?v=', $body);
            $this->assertStringContainsString('href="/re-buji/assets/apple-touch-icon.png?v=', $body);
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

        foreach (['#eef1f4', '#d5dbe1', '#7d8892', '#ffffff', '#26323a', '#5f6b74', '#e3e7eb', '#2b6cb0', '#c0392b', '#9a5800', '#237356'] as $color) {
            $this->assertStringContainsString($color, $css);
        }

        $this->assertStringNotContainsString('#f4f6f8', $css);
    }

    #[Test]
    public function flattensBoxesInsideACard(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');

        // A box inside a card would read as a card inside a card.
        $this->assertMatchesRegularExpression('/\.card fieldset\s*\{[^}]*border:0/', $css);
        $this->assertMatchesRegularExpression('/\.card details\s*\{[^}]*box-shadow:none/', $css);
        $this->assertMatchesRegularExpression('/\.card table\.stack tr\s*\{[^}]*box-shadow:none/', $css);
        $this->assertMatchesRegularExpression('/input,select,textarea\s*\{[^}]*border:1px solid var\(--field\)/', $css);
    }

    #[Test]
    public function spacesACardAfterAFormOnTheGround(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');

        // A form has no bottom margin, so a button on the ground would touch the card below it.
        $this->assertMatchesRegularExpression('/form\+\.card,form\+\.calm\s*\{[^}]*margin-top:16px/', $css);
    }

    #[Test]
    public function shipsTheIcons(): void
    {
        $assets = dirname(__DIR__, 2) . '/public/assets/';
        $svg = (string) @file_get_contents($assets . 'favicon.svg');
        $xml = simplexml_load_string($svg);

        $this->assertNotFalse($xml, 'favicon.svg should be well-formed');
        $this->assertSame('0 0 32 32', (string) $xml['viewBox']);
        $this->assertStringContainsString('#2b6cb0', $svg);
        $this->assertStringContainsString('<path', $svg);
        // Drawn as lines, so it looks the same on every device.
        $this->assertStringNotContainsString('<text', $svg);

        foreach (['favicon-32.png' => 32, 'apple-touch-icon.png' => 180] as $file => $size) {
            $image = @getimagesize($assets . $file);

            $this->assertIsArray($image, $file . ' should be an image');
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$image[0], $image[1], $image[2]], $file);
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
    public function showsTheIconBesideTheNameOnPagesWithANav(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $body = (string) $app->handle(TestApp::request('GET', '/employees'))->getBody();

            $this->assertStringContainsString('<header><span class="brand"><img src="/assets/favicon.svg?v=' . Version::CURRENT . '" alt="" width="28" height="28"><strong>Re:無事</strong></span>', $body);
            $this->assertStringNotContainsString('brand-big', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsTheSmallIconWhenAPageIsNotBare(): void
    {
        [, $container, $root] = TestApp::create();

        try {
            $engine = $container->get(Engine::class);
            $this->assertInstanceOf(Engine::class, $engine);
            // No nav section, as on the database update page while a change is pending.
            $body = $engine->render('layout', ['title' => 'x', 'bare' => false]);

            $this->assertStringContainsString('<header><span class="brand"><img src="/assets/favicon.svg?v=' . Version::CURRENT . '" alt="" width="28" height="28"><strong>Re:無事</strong></span></header>', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsTheIconUnderASubdirectory(): void
    {
        $root = TestApp::root();

        try {
            [$app] = TestApp::build($root, [Url::class => new Url('/re-buji')]);
            $body = (string) $app->handle(TestApp::request('GET', '/install/environment'))->getBody();

            $this->assertStringContainsString('<img src="/re-buji/assets/favicon.svg?v=', $body);
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

            $this->assertStringContainsString('<header class="brand-big"><img src="/assets/favicon.svg?v=' . Version::CURRENT . '" alt="" width="56" height="56"><strong>Re:無事</strong></header>', $body);
            $this->assertStringNotContainsString('class="brand"', $body);
            $this->assertStringContainsString('class="bare"', $body);
            $this->assertStringNotContainsString('<nav', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
