<?php

declare(strict_types=1);

namespace ReBuji\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use LogicException;
use Psr\Clock\ClockInterface;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeValidator;
use ReBuji\Mail\Imap\ImapSettings;
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
        'imap.host' => '',
        'imap.port' => '993',
        'imap.encryption' => 'ssl',
        'imap.username' => '',
        'imap.password' => '',
    ];

    /**
     * The settings that REBUJI_* environment variables decide (when REBUJI_ENV_CONFIG=1), by setting name.
     */
    public const array ENVIRONMENT = [
        'smtp.host' => 'REBUJI_SMTP_HOST',
        'smtp.port' => 'REBUJI_SMTP_PORT',
        'smtp.encryption' => 'REBUJI_SMTP_ENCRYPTION',
        'smtp.username' => 'REBUJI_SMTP_USERNAME',
        'smtp.password' => 'REBUJI_SMTP_PASSWORD',
        'mail.from_address' => 'REBUJI_MAIL_FROM_ADDRESS',
        'mail.from_name' => 'REBUJI_MAIL_FROM_NAME',
        'imap.host' => 'REBUJI_IMAP_HOST',
        'imap.port' => 'REBUJI_IMAP_PORT',
        'imap.encryption' => 'REBUJI_IMAP_ENCRYPTION',
        'imap.username' => 'REBUJI_IMAP_USERNAME',
        'imap.password' => 'REBUJI_IMAP_PASSWORD',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ClockInterface $clock,
        private readonly Environment $environment = new Environment([]),
    ) {
        //
    }

    /**
     * The name of the environment variable that decides this setting, or null when the admins can change it.
     */
    public function lockedBy(string $name): ?string
    {
        $variable = self::ENVIRONMENT[$name] ?? null;

        if ($variable === null || !$this->environment->enabled() || $this->environment->get($variable) === null) {
            return null;
        }

        return $variable;
    }

    /**
     * Problems in the values given by environment variables, in Japanese. Only those values are checked.
     *
     * @return list<string>
     */
    public function environmentErrors(): array
    {
        $errors = [];

        foreach (['smtp', 'imap'] as $account) {
            $port = $this->environmentValue($account . '.port');

            if ($port !== null && !(ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535)) {
                $errors[] = self::ENVIRONMENT[$account . '.port'] . ' は 1〜65535 の数にしてください。';
            }

            $encryption = $this->environmentValue($account . '.encryption');

            if ($encryption !== null && !in_array($encryption, ['ssl', 'starttls'], true)) {
                $errors[] = self::ENVIRONMENT[$account . '.encryption'] . ' は ssl か starttls にしてください。';
            }
        }

        $from = $this->environmentValue('mail.from_address');

        if ($from !== null && !EmployeeValidator::isAddress($from)) {
            $errors[] = self::ENVIRONMENT['mail.from_address'] . ' がメールアドレスの形ではありません。';
        }

        return $errors;
    }

    private function environmentValue(string $name): ?string
    {
        return $this->lockedBy($name) === null ? null : $this->environment->get(self::ENVIRONMENT[$name]);
    }

    public function get(string $name): string
    {
        self::assertKnown($name);

        if ($this->lockedBy($name) !== null) {
            return (string) $this->environment->get(self::ENVIRONMENT[$name]);
        }

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
            $this->caFile(),
        );
    }

    /**
     * The reply mailbox, or null until its server is saved. An empty user name or password means the sending account's:
     * replies come back to the account mail is sent from (design 5.1).
     */
    public function imap(): ?ImapSettings
    {
        $host = $this->get('imap.host');

        if ($host === '') {
            return null;
        }

        $username = $this->get('imap.username');
        $password = $this->get('imap.password');

        return new ImapSettings(
            $host,
            $this->int('imap.port'),
            $this->get('imap.encryption'),
            $username === '' ? $this->get('smtp.username') : $username,
            $password === '' ? $this->get('smtp.password') : $password,
            $this->caFile(),
        );
    }

    private function caFile(): string
    {
        return $this->environment->enabled() ? ($this->environment->get('REBUJI_MAIL_CA_FILE') ?? '') : '';
    }

    /**
     * Names decided by the environment are skipped without a word: they cannot be changed here.
     *
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        foreach (array_keys($values) as $name) {
            self::assertKnown($name);
        }

        $values = array_filter($values, fn(string $name): bool => $this->lockedBy($name) === null, ARRAY_FILTER_USE_KEY);

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
