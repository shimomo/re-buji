<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeZone;
use JmaXml\Earthquake\SeismicIntensity;
use Psr\Clock\ClockInterface;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Settings\Settings;
use ReBuji\Settings\SmtpSettings;

/**
 * Writes the contact mails (design 5.1) from the admins' wording. The number in the subject, the resend and drill
 * marks, and the line saying a quake arrived late are always added, whatever the wording.
 *
 * @author shimomo
 */
final class NotificationMail
{
    public const array PLACEHOLDERS = ['{名前}', '{発生時刻}', '{地震}', '{市町村と震度}'];

    public const string FIRST = 'first';

    public const string RESEND = 'resend';

    public const string CANCEL = 'cancel';

    public function __construct(private readonly Settings $settings, private readonly ClockInterface $clock)
    {
        //
    }

    /**
     * @param self::FIRST|self::RESEND|self::CANCEL|string $kind
     */
    public function compose(Earthquake $quake, Notification $notification, string $kind, string $to): OutgoingMail
    {
        $account = $this->account();
        $prefix = '[安否確認 #' . $notification->token . '] ';

        $values = [
            '{名前}' => $notification->employeeName,
            '{発生時刻}' => $quake->detectedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G時i分'),
            '{地震}' => $quake->title,
            '{市町村と震度}' => implode("\n", array_map(self::cityLine(...), $notification->cities)),
        ];

        // The notice names the quake: someone contacted twice must know which contact it takes back.
        if ($kind === self::CANCEL) {
            return self::mail(
                $account,
                $to,
                $prefix . '先ほどの連絡は取り消されました',
                strtr("{名前} さん\n\n{発生時刻}ごろの地震（{地震}）について先ほどお送りした安否確認の連絡は、気象庁が地震の情報を取り消したため、取り消します。返信は不要です。\n", $values),
            );
        }
        $subject = $prefix . ($kind === self::RESEND ? '【再送】' : '') . ($quake->isDrill() ? '【訓練】' : '') . strtr($this->settings->get('mail.subject'), $values);
        $body = strtr($this->settings->get('mail.body'), $values);

        if ($quake->late) {
            $hours = max(1, intdiv($this->clock->now()->getTimestamp() - $quake->detectedAt->getTimestamp(), 3600));
            $body = $hours . " 時間前の地震です。気象庁の情報が遅れて届いたため、今お送りしています。\n\n" . $body;
        }

        if ($quake->isDrill()) {
            $body = "これは訓練です。実際の地震ではありません。\n\n" . $body;
        }

        return self::mail($account, $to, $subject, $body);
    }

    /**
     * Tells an admin about problems the home page shows (design 6.2).
     *
     * @param list<string> $problems
     */
    public function watchMail(string $to, array $problems): OutgoingMail
    {
        $lines = implode("\n", array_map(static fn(string $problem): string => '・' . $problem, $problems));

        return self::mail($this->account(), $to, '[Re:無事] 確かめてほしいことがあります', "Re:無事 の管理者の皆さん\n\n次のことが起きています。管理画面のホームで確かめてください。\n\n" . $lines . "\n");
    }

    public function testMail(string $to): OutgoingMail
    {
        return self::mail($this->account(), $to, 'Re:無事 のテストメール', 'Re:無事 からのテストメールです。このメールが届いていれば、送信の設定は正しく動いています。');
    }

    private function account(): SmtpSettings
    {
        return $this->settings->smtp() ?? throw new MailUnavailable('メールの送信の設定がありません。「設定」で SMTP を登録してください。');
    }

    private static function mail(SmtpSettings $account, string $to, string $subject, string $body): OutgoingMail
    {
        $domain = substr($account->fromAddress, (int) strrpos($account->fromAddress, '@') + 1);

        return new OutgoingMail($account->fromAddress, $account->fromName, $to, $subject, $body, bin2hex(random_bytes(16)) . '@' . $domain);
    }

    private static function cityLine(MatchedCity $city): string
    {
        $line = '・' . ($city->label === '' ? '' : $city->label . '：') . $city->place;

        return $city->basis === MatchedCity::BASIS_NONE ? $line : $line . '　' . self::intensity($city);
    }

    private static function intensity(MatchedCity $city): string
    {
        $label = SeismicIntensity::tryFrom($city->intensity ?? '')?->label();

        if ($city->notYetReceived) {
            return ($label ?? SeismicIntensity::FiveLower->label()) . '以上の可能性（観測値が未着）';
        }

        return ($label ?? '震度不明') . ($city->basis === MatchedCity::BASIS_AREA ? '（地域の震度）' : '');
    }
}
