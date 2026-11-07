<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\NotificationMail;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Settings\Settings;
use ReBuji\Tests\Support\FrozenClock;

/**
 * @author shimomo
 */
final class NotificationMailTest extends TestCase
{
    private const string DETECTED = '2009-08-10T20:07:00Z';

    #[Test]
    public function writesTheFirstContact(): void
    {
        $mail = self::mails()->compose(self::quake(), self::contact(), 'first', 'hanako@example.com');

        $this->assertSame('anpi@example.co.jp', $mail->fromAddress);
        $this->assertSame('安否確認', $mail->fromName);
        $this->assertSame('hanako@example.com', $mail->to);
        $this->assertSame('[安否確認 #a1b2c3d4] 地震がありました（駿河湾・最大震度6弱）', $mail->subject);
        $this->assertStringStartsWith("山田 花子 さん\n\n8月11日 5時07分ごろ、地震がありました（駿河湾・最大震度6弱）。\n", $mail->body);
        $this->assertStringContainsString("\n・勤務地：静岡県 掛川市　震度5弱\n", $mail->body);
        $this->assertStringContainsString('このメールに返信してください。', $mail->body);
        $this->assertStringContainsString('本文は空のままで構いません。', $mail->body);
    }

    #[Test]
    public function usesTheEditedWording(): void
    {
        $settings = self::settings();
        $settings->save(['mail.subject' => '【安否】{地震}', 'mail.body' => "{名前}様\n{市町村と震度}\n返信してください。\n"]);

        $mail = (new NotificationMail($settings, new FrozenClock()))->compose(self::quake(), self::contact(), 'first', 'hanako@example.com');

        $this->assertSame('[安否確認 #a1b2c3d4] 【安否】駿河湾・最大震度6弱', $mail->subject);
        $this->assertSame("山田 花子様\n・勤務地：静岡県 掛川市　震度5弱\n返信してください。\n", $mail->body);
    }

    #[Test]
    public function marksResends(): void
    {
        $mail = self::mails()->compose(self::quake(), self::contact(), 'resend', 'hanako@example.com');

        $this->assertSame('[安否確認 #a1b2c3d4] 【再送】地震がありました（駿河湾・最大震度6弱）', $mail->subject);
    }

    #[Test]
    public function marksDrills(): void
    {
        $drill = self::quake(kind: Earthquake::KIND_DRILL, title: '訓練');
        $contact = self::contact([new MatchedCity('2221300', '勤務地', '静岡県 掛川市', null, false, MatchedCity::BASIS_NONE)]);

        $first = self::mails()->compose($drill, $contact, 'first', 'hanako@example.com');
        $resend = self::mails()->compose($drill, $contact, 'resend', 'hanako@example.com');

        $this->assertSame('[安否確認 #a1b2c3d4] 【訓練】地震がありました（訓練）', $first->subject);
        $this->assertSame('[安否確認 #a1b2c3d4] 【再送】【訓練】地震がありました（訓練）', $resend->subject);
        $this->assertStringStartsWith("これは訓練です。実際の地震ではありません。\n\n山田 花子 さん\n", $first->body);
        $this->assertStringContainsString("\n・勤務地：静岡県 掛川市\n", $first->body);
    }

    #[Test]
    public function saysHowLateTheQuakeIs(): void
    {
        $mail = self::mails('2009-08-11T01:27:00Z')->compose(self::quake(late: true), self::contact(), 'first', 'hanako@example.com');

        $this->assertStringStartsWith("5 時間前の地震です。気象庁の情報が遅れて届いたため、今お送りしています。\n\n山田 花子 さん\n", $mail->body);
    }

    #[Test]
    public function saysAtLeastAnHourForALateQuake(): void
    {
        $mail = self::mails('2009-08-10T20:37:00Z')->compose(self::quake(late: true), self::contact(), 'first', 'hanako@example.com');

        $this->assertStringStartsWith('1 時間前の地震です。', $mail->body);
    }

    #[Test]
    public function describesUncertainIntensities(): void
    {
        $contact = self::contact([
            new MatchedCity('2221300', '勤務地', '静岡県 掛川市', '6-', false, MatchedCity::BASIS_AREA),
            new MatchedCity('2210200', '', '静岡県 静岡駿河区', null, true, MatchedCity::BASIS_CITY),
            new MatchedCity('2230600', '実家', '静岡県 西伊豆町', '5+', true, MatchedCity::BASIS_CITY),
        ]);

        $body = self::mails()->compose(self::quake(), $contact, 'first', 'hanako@example.com')->body;

        $this->assertStringContainsString(
            "・勤務地：静岡県 掛川市　震度6弱（地域の震度）\n・静岡県 静岡駿河区　震度5弱以上の可能性（観測値が未着）\n・実家：静岡県 西伊豆町　震度5強以上の可能性（観測値が未着）\n",
            $body,
        );
    }

    #[Test]
    public function writesTheCancelNotice(): void
    {
        $mail = self::mails()->compose(self::quake(), self::contact(), 'cancel', 'hanako@example.com');

        $this->assertSame('[安否確認 #a1b2c3d4] 先ほどの連絡は取り消されました', $mail->subject);
        $this->assertSame("山田 花子 さん\n\n先ほどお送りした安否確認の連絡は、気象庁が地震の情報を取り消したため、取り消します。返信は不要です。\n", $mail->body);
    }

    #[Test]
    public function makesUnguessableMessageIds(): void
    {
        $mails = self::mails();
        $first = $mails->compose(self::quake(), self::contact(), 'first', 'hanako@example.com')->messageId;
        $second = $mails->compose(self::quake(), self::contact(), 'first', 'hanako@example.com')->messageId;

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}@example\.co\.jp$/', $first);
    }

    #[Test]
    public function writesATestMail(): void
    {
        $mail = self::mails()->testMail('admin@example.com');

        $this->assertSame('admin@example.com', $mail->to);
        $this->assertSame('Re:無事 のテストメール', $mail->subject);
        $this->assertSame('Re:無事 からのテストメールです。このメールが届いていれば、送信の設定は正しく動いています。', $mail->body);
    }

    #[Test]
    public function needsTheMailSettings(): void
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        $this->expectException(MailUnavailable::class);

        (new NotificationMail(new Settings($db, $clock), $clock))->testMail('admin@example.com');
    }

    private static function mails(string $now = '2009-08-10T20:12:00Z'): NotificationMail
    {
        return new NotificationMail(self::settings(), new FrozenClock(new DateTimeImmutable($now)));
    }

    /**
     * Settings with the sending account filled in.
     */
    private static function settings(): Settings
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $settings = new Settings($db, $clock);
        $settings->save(['smtp.host' => 'smtp.example.co.jp', 'mail.from_address' => 'anpi@example.co.jp']);

        return $settings;
    }

    private static function quake(string $kind = Earthquake::KIND_QUAKE, string $title = '駿河湾・最大震度6弱', bool $late = false): Earthquake
    {
        $drill = $kind === Earthquake::KIND_DRILL;

        return new Earthquake(
            1,
            $kind,
            $drill ? null : '20090811050711',
            $title,
            $drill ? null : '駿河湾',
            $drill ? null : '6.6',
            $drill ? null : '6-',
            new DateTimeImmutable(self::DETECTED),
            $drill ? null : '震源・震度に関する情報',
            $drill ? null : new DateTimeImmutable('2009-08-10T20:21:00Z'),
            $drill ? null : '5-',
            $late ? Earthquake::RELEASED : Earthquake::AUTO,
            $late,
            null,
        );
    }

    /**
     * @param list<MatchedCity>|null $cities
     */
    private static function contact(?array $cities = null): Notification
    {
        return new Notification(
            1,
            1,
            1,
            '山田 花子',
            '静岡支店',
            'a1b2c3d4',
            $cities ?? [new MatchedCity('2221300', '勤務地', '静岡県 掛川市', '5-', false, MatchedCity::BASIS_CITY)],
            5,
            Notification::PENDING,
            new DateTimeImmutable(self::DETECTED),
            0,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }
}
