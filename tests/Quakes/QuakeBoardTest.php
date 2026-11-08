<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Quakes\Board\BoardRow;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * The clock is at 2009-08-10T20:12:00Z (5:12 in Tokyo).
 *
 * @author shimomo
 */
final class QuakeBoardTest extends TestCase
{
    #[Test]
    public function sortsPeopleByTheirAnswer(): void
    {
        $world = self::world();
        $set = [
            "answer = 'safe', replied_at = '2009-08-10T20:20:00Z'",
            "answer = 'safe', replied_at = '2009-08-10T20:21:00Z'",
            "answer = 'needs_check', replied_at = '2009-08-10T20:22:00Z'",
            "answer = 'needs_check', replied_at = '2009-08-10T20:23:00Z', checked_at = '2009-08-10T20:30:00Z', checked_by = '安否 太郎'",
            "state = 'sent', sent_count = 1, first_sent_at = '2009-08-10T20:12:00Z', last_sent_at = '2009-08-10T20:12:00Z'",
            "state = 'failed', sent_count = 1",
            "state = 'pending'",
            "state = 'skipped'",
        ];

        foreach ($set as $i => $columns) {
            $id = $world->contact($world->person('従業員 ' . $i, '2221300'));
            $world->db->execute('UPDATE notifications SET ' . $columns . ' WHERE id = ?', [$id]);
        }

        $board = $world->board()->build($world->quake('20090811050711'));

        $this->assertCount(3, $board['noReply']);
        $this->assertCount(1, $board['needsCheck']);
        $this->assertCount(1, $board['checked']);
        $this->assertCount(2, $board['safe']);
        $this->assertSame(7, $board['total']);
    }

    #[Test]
    public function explainsWhyThereIsNoReply(): void
    {
        $world = self::world();
        $reasons = [
            "state = 'failed', sent_count = 1" => '届かなかった',
            "state = 'sent', sent_count = 1, auto_replied_at = '2009-08-10T20:13:00Z'" => '不在の自動応答だけ',
            "state = 'sent', sent_count = 2, first_sent_at = '2009-08-10T20:12:00Z', last_sent_at = '2009-08-10T21:12:00Z', next_send_at = '2009-08-10T22:12:00Z'" => '5:12 に送信、6:12 に再送（次の再送 7:12）',
            "state = 'sent', sent_count = 1, first_sent_at = '2009-08-10T20:12:00Z', last_sent_at = '2009-08-10T20:12:00Z'" => '5:12 に送信',
            "state = 'pending', not_before = '2009-08-10T20:20:00Z'" => '送信待ち（5:20 に送る予定）',
            "state = 'pending', not_before = '2009-08-10T20:07:00Z'" => '送信待ち（次の cron で送ります）',
            "state = 'held'" => '保留（遅れて届いた地震）',
            "state = 'cancelled', sent_count = 0" => '取消のため送っていません',
            "state = 'cancelled', sent_count = 1, first_sent_at = '2009-08-10T20:12:00Z'" => '5:12 に送信（取消済み）',
        ];

        foreach (array_keys($reasons) as $i => $columns) {
            $id = $world->contact($world->person('従業員 ' . $i, '2221300'));
            $world->db->execute('UPDATE notifications SET ' . $columns . ' WHERE id = ?', [$id]);
        }

        $shown = array_map(static fn(BoardRow $row): string => $row->reason, $world->board()->build($world->quake('20090811050711'))['noReply']);
        sort($shown);
        $expected = array_values($reasons);
        sort($expected);

        $this->assertSame($expected, $shown);
    }

    #[Test]
    public function saysWhenTheLimitHoldsSomeoneBack(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);

        for ($i = 0; $i < 10; $i++) {
            $world->messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', new DateTimeImmutable('2009-08-10T20:05:00Z'));
        }

        $world->contact($world->person('山田 花子', '2221300'), notBefore: new DateTimeImmutable('2009-08-10T20:07:00Z'));

        $this->assertSame('送信待ち（上限のため 6:05 予定）', $world->board()->build($world->quake('20090811050711'))['noReply'][0]->reason);
    }

    #[Test]
    public function listsTheProblemsFirst(): void
    {
        $world = self::world();

        foreach (["state = 'pending'", "state = 'sent', sent_count = 1, first_sent_at = '2009-08-10T20:12:00Z'", "state = 'sent', sent_count = 1, auto_replied_at = '2009-08-10T20:13:00Z'", "state = 'failed', sent_count = 1"] as $i => $columns) {
            $id = $world->contact($world->person('従業員 ' . $i, '2221300'));
            $world->db->execute('UPDATE notifications SET ' . $columns . ' WHERE id = ?', [$id]);
        }

        $order = array_map(static fn(BoardRow $row): string => $row->name, $world->board()->build($world->quake('20090811050711'))['noReply']);

        $this->assertSame(['従業員 3', '従業員 2', '従業員 1', '従業員 0'], $order);
    }

    #[Test]
    public function showsFoldedPeopleWithTheContactTheyWereFoldedInto(): void
    {
        $world = self::world();
        $person = $world->person('山田 花子', '2221300');
        $first = $world->contact($person);
        $world->db->execute("UPDATE notifications SET answer = 'safe', replied_at = '2009-08-10T20:20:00Z' WHERE id = ?", [$first]);
        $later = $world->otherQuake('20090811050900');
        $world->contact($person, state: Notification::MERGED, quake: $later);
        $world->db->execute('UPDATE notifications SET merged_into_id = ? WHERE earthquake_id = ?', [$first, $later]);

        $board = $world->board()->build($world->quake('20090811050900'));

        $this->assertCount(1, $board['safe']);
        $this->assertSame('駿河湾・最大震度6弱', $board['safe'][0]->foldedInto);
        $this->assertSame($first, $board['safe'][0]->contactId);
    }

    #[Test]
    public function showsTheRepliesOfPeopleToCheck(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300'));
        $world->db->execute("UPDATE notifications SET answer = 'needs_check', replied_at = '2009-08-10T20:28:00Z' WHERE id = ?", [$contact]);
        $world->replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '病院に行きます', null, new DateTimeImmutable('2009-08-10T20:40:00Z'));
        $world->replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '足を怪我しました', null, new DateTimeImmutable('2009-08-10T20:28:00Z'));
        $world->replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '', null, new DateTimeImmutable('2009-08-10T20:30:00Z'));

        $row = $world->board()->build($world->quake('20090811050711'))['needsCheck'][0];

        $this->assertSame(['足を怪我しました', '病院に行きます'], $row->bodies);
        $this->assertSame('5:28', $row->repliedAt);
        $this->assertSame('静岡県 掛川市 震度5弱', $row->places);
    }

    private static function world(): QuakeWorld
    {
        return new QuakeWorld('2009-08-10T20:12:00Z');
    }
}
