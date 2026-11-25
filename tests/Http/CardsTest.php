<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use DateTimeImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use League\Plates\Engine;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\Notification;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * Every page keeps what a person reads in white cards, so nothing melts into the gray ground, and no card sits in a card.
 *
 * @author shimomo
 */
final class CardsTest extends TestCase
{
    #[Test]
    public function putsTheHomeInCards(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $this->assertInCards($app, '/');

            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::SENT);
            $check = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:10:00Z'));

            $body = $this->assertInCards($app, '/');
            $this->assertStringContainsString('<h2>⚠ 要確認</h2>', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function putsTheQuakePagesInCards(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $this->assertInCards($app, '/earthquakes');

            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::SENT);
            $check = $records->contact($quake, Notification::SENT);
            $safe = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:10:00Z'));
            $records->notifications->recordAnswer($safe, Notification::SAFE, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:12:00Z'));
            $held = $records->quake('20261106090000', '千葉県東方沖・最大震度5弱', '2026-11-06T00:00:00Z', Earthquake::HELD);

            $this->assertInCards($app, '/earthquakes', '/earthquakes/' . $quake, '/earthquakes/' . $held, '/contacts/' . $check . '/proxy');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function putsTheEmployeeAndDrillPagesInCards(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $this->assertInCards($app, '/employees', '/drills/new');

            $employees = $container->get(EmployeeRepository::class);
            $this->assertInstanceOf(EmployeeRepository::class, $employees);
            $id = $employees->create(new EmployeeInput('青木 一郎', '総務部', ['aoki@example.com'], [new EmployeeCity('2221300', '')], '', true));

            $this->assertInCards($app, '/employees', '/employees/new', '/employees/' . $id, '/employees/import', '/drills/new');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function putsTheOtherPagesInCards(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:05:00Z'));

            $this->assertInCards($app, '/mail/unmatched', '/admins', '/settings', '/admin/database', '/more');

            $engine = $container->get(Engine::class);
            $this->assertInstanceOf(Engine::class, $engine);
            $admin = ['id' => 1, 'name' => '安否 太郎', 'email' => TestApp::ADMIN_EMAIL];
            $pending = $engine->render('admin/database-update', ['title' => 'DB の更新', 'admin' => $admin, 'version' => '0.4.4', 'applied' => null, 'busy' => false, 'pending' => [['version' => '20261125000000', 'description' => '例']]]);

            $this->assertSame([], self::misplaced($pending));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheBarePagesInCards(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $this->assertInCards($app, '/login');
            $engine = $container->get(Engine::class);
            $this->assertInstanceOf(Engine::class, $engine);
            $this->assertSame([], self::misplaced($engine->render('error', ['title' => 'エラー', 'message' => '例'])));

            [$fresh, , $freshRoot] = TestApp::create();

            try {
                $this->assertInCards($fresh, '/install/environment');
            } finally {
                TemporaryDirectory::remove($freshRoot);
            }
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function separatesTheFinishPagesBackLinks(): void
    {
        [, $container, $root] = TestApp::create();

        try {
            $engine = $container->get(Engine::class);
            $this->assertInstanceOf(Engine::class, $engine);
            $body = $engine->render('install/finish', ['title' => '仕上げ', 'steps' => [['key' => 'finish', 'title' => '仕上げ']], 'step' => 'finish', 'errors' => ['例'], 'cronPath' => '/home/you/re-buji/bin/cron.php', 'statusUrl' => 'https://example.jp/status/x']);

            $this->assertSame([], self::misplaced($body));
            $this->assertSame(1, preg_match('/<p class="back links">(.*?)<\/p>/su', $body, $match));
            // Spaced by the stylesheet; without it, the two links would run together.
            $this->assertMatchesRegularExpression('/<\/a>\s+<a /', $match[1]);
            $this->assertStringNotContainsString('　', $match[1]);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * Opens each page and checks nothing on it is out of place; returns the last page's body.
     *
     * @param App<ContainerInterface> $app
     */
    private function assertInCards(App $app, string ...$paths): string
    {
        $body = '';

        foreach ($paths as $path) {
            $response = $app->handle(TestApp::request('GET', $path));
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame([], self::misplaced($body), $path);
        }

        return $body;
    }

    /**
     * What sits out of place on a page: text, tables, lists, headings and fields on the ground instead of in a box, and a
     * card inside a card, as "tag: text" lines so a failure names them. A fieldset inside a card is fine: the stylesheet
     * flattens it.
     *
     * @return list<string>
     */
    private static function misplaced(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $has = static fn(string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
        $box = $has('card') . ' or self::fieldset or ' . $has('alert') . ' or ' . $has('calm') . ' or ' . $has('tiles');
        $queries = [
            '//body//*[self::p or self::table or self::ul or self::ol or self::h2 or self::label or self::details]'
                . '[not(ancestor-or-self::*[' . $box . '])][not(ancestor::header)]'
                . '[not(' . $has('back') . ' or ' . $has('progress') . ' or ' . $has('dots') . ')]',
            '//*[' . $has('card') . '][ancestor::*[' . $has('card') . ' or self::fieldset]] | //fieldset[ancestor::fieldset]',
        ];
        $lines = [];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                throw new LogicException('Bad query: ' . $query);
            }

            for ($i = 0; $i < $nodes->length; $i++) {
                $node = $nodes->item($i);
                assert($node instanceof DOMNode);
                $lines[] = $node->nodeName . ': ' . trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            }
        }

        return $lines;
    }
}
