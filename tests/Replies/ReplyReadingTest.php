<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Replies\ReplyReading;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * The clock is at 2009-08-10T20:12:00Z (8月11日 5時12分 in Tokyo).
 *
 * @author shimomo
 */
final class ReplyReadingTest extends TestCase
{
    #[Test]
    public function saysNothingWhileRepliesAreRead(): void
    {
        $world = self::world();
        $world->state->set('imap.last_checked_at', '2009-08-10T19:43:00Z');

        $this->assertNull(self::reading($world)->staleWarning($world->clock->now()));
    }

    #[Test]
    public function warnsWhenRepliesHaveNotBeenReadForHalfAnHour(): void
    {
        $world = self::world();
        $world->state->set('imap.last_checked_at', '2009-08-10T19:41:00Z');

        $this->assertSame('返信を 30 分以上読めていません（最後は 8月11日 4時41分）。', self::reading($world)->staleWarning($world->clock->now()));
    }

    #[Test]
    public function warnsWhenRepliesWereNeverRead(): void
    {
        $world = self::world();
        $world->state->set('imap.since', '2009-08-10T19:00:00Z');

        $this->assertSame('返信を 30 分以上読めていません（まだ一度も読めていません）。', self::reading($world)->staleWarning($world->clock->now()));
    }

    #[Test]
    public function leavesOtherProblemsToTheirOwnWarnings(): void
    {
        $world = self::world();
        $world->state->set('imap.last_checked_at', '2009-08-10T19:00:00Z');
        $world->state->set('imap.last_error', 'IMAP のサーバーが断りました');

        $this->assertNull(self::reading($world)->staleWarning($world->clock->now()));

        $world->state->set('imap.last_error', '');
        $world->settings->save(['imap.host' => '']);

        $this->assertNull(self::reading($world)->staleWarning($world->clock->now()));
    }

    private static function world(): QuakeWorld
    {
        $world = new QuakeWorld('2009-08-10T20:12:00Z');
        $world->settings->save(['imap.host' => 'imap.example.co.jp', 'smtp.username' => 'anpi', 'smtp.password' => 'secret']);

        return $world;
    }

    private static function reading(QuakeWorld $world): ReplyReading
    {
        return new ReplyReading($world->settings, $world->state);
    }
}
