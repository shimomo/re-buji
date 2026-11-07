<?php

declare(strict_types=1);

namespace ReBuji\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use LogicException;
use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Settings the admins change on the settings page, with the defaults of design 4 and 5.3. Each read goes to the
 * database, so a change takes effect within the same cron run.
 *
 * @author shimomo
 */
final class Settings
{
    public const array DEFAULTS = [
        'threshold' => '5-',
        'delay_minutes' => '0',
        'late_after_hours' => '3',
        'resend_count' => '3',
        'resend_interval_minutes' => '60',
        'hourly_limit' => '100',
        'mail.subject' => '地震がありました（{地震}）',
        'mail.body' => <<<'BODY'
            {名前} さん

            {発生時刻}ごろ、地震がありました（{地震}）。
            登録している次の場所が、強く揺れた可能性があります。

            {市町村と震度}

            このメールに返信してください。返信が届けば「無事」として記録します。
            本文は空のままで構いません。
            けがをした、出社できないなどの事情があれば、本文に書いてください。

            BODY,
        'smtp.host' => '',
        'smtp.port' => '587',
        'smtp.encryption' => 'starttls',
        'smtp.username' => '',
        'smtp.password' => '',
        'mail.from_address' => '',
        'mail.from_name' => '安否確認',
    ];

    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function get(string $name): string
    {
        self::assertKnown($name);
        $value = $this->db->fetchValue('SELECT value FROM settings WHERE name = ?', [$name]);

        return $value === null ? self::DEFAULTS[$name] : (string) $value;
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    public function threshold(): SeismicIntensity
    {
        return SeismicIntensity::tryFrom($this->get('threshold')) ?? SeismicIntensity::FiveLower;
    }

    public function smtp(): ?SmtpSettings
    {
        $host = $this->get('smtp.host');
        $from = $this->get('mail.from_address');

        if ($host === '' || $from === '') {
            return null;
        }

        return new SmtpSettings(
            $host,
            $this->int('smtp.port'),
            $this->get('smtp.encryption'),
            $this->get('smtp.username'),
            $this->get('smtp.password'),
            $from,
            $this->get('mail.from_name'),
        );
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        foreach (array_keys($values) as $name) {
            self::assertKnown($name);
        }

        $this->db->transaction(function (Database $db) use ($values): void {
            $now = Time::format($this->clock->now());

            foreach ($values as $name => $value) {
                if ($db->execute('UPDATE settings SET value = ?, updated_at = ? WHERE name = ?', [$value, $now, $name]) === 0) {
                    $db->execute('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [$name, $value, $now]);
                }
            }
        });
    }

    private static function assertKnown(string $name): void
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new LogicException('Unknown setting: ' . $name);
        }
    }
}
