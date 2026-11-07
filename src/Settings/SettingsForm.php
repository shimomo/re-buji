<?php

declare(strict_types=1);

namespace ReBuji\Settings;

use ReBuji\Employees\EmployeeValidator;
use ReBuji\Mail\NotificationMail;

/**
 * The settings form, shared by the settings page and the installer's mail step: its fields and their checks.
 *
 * @author shimomo
 */
final class SettingsForm
{
    /**
     * Form fields and the settings they set. PHP turns dots in posted names into underscores, so the fields use
     * underscores.
     */
    public const array FIELDS = [
        'threshold' => 'threshold',
        'delay_minutes' => 'delay_minutes',
        'late_after_hours' => 'late_after_hours',
        'resend_count' => 'resend_count',
        'resend_interval_minutes' => 'resend_interval_minutes',
        'hourly_limit' => 'hourly_limit',
        'mail_subject' => 'mail.subject',
        'mail_body' => 'mail.body',
        'smtp_host' => 'smtp.host',
        'smtp_port' => 'smtp.port',
        'smtp_encryption' => 'smtp.encryption',
        'smtp_username' => 'smtp.username',
        'smtp_password' => 'smtp.password',
        'mail_from_address' => 'mail.from_address',
        'mail_from_name' => 'mail.from_name',
        'imap_host' => 'imap.host',
        'imap_port' => 'imap.port',
        'imap_encryption' => 'imap.encryption',
        'imap_username' => 'imap.username',
        'imap_password' => 'imap.password',
    ];

    /**
     * 震度 3 and below would send too often and use up the mail limit.
     */
    public const array THRESHOLDS = ['4', '5-', '5+', '6-', '6+', '7'];

    /**
     * Whole numbers on the form: the field, its range and the message when out of it.
     */
    private const array NUMBERS = [
        'delay_minutes' => [0, 60, '送るまでの待ち時間は 0〜60 分にしてください。'],
        'late_after_hours' => [1, 72, '遅れて届いた地震とみなすのは 1〜72 時間にしてください。'],
        'resend_count' => [0, 5, '再送の回数は 0〜5 回にしてください。'],
        'resend_interval_minutes' => [10, 1440, '再送の間隔は 10〜1440 分にしてください。'],
        'hourly_limit' => [10, 10000, '1 時間の上限は 10〜10000 通にしてください。'],
        'smtp_port' => [1, 65535, 'ポート番号は 1〜65535 にしてください。'],
    ];

    /**
     * @param array<array-key, mixed> $body the posted form
     *
     * @return array<string, string> by form field
     */
    public static function read(array $body): array
    {
        $values = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = self::text($body[$field] ?? null);
            $values[$field] = $field === 'mail_body' ? str_replace("\r\n", "\n", $value) : trim($value);
        }

        return $values;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, string> $values by form field
     * @param bool $accountsRequired true in the installer: sending and receiving must both be set up
     *
     * @return list<string>
     */
    public static function validate(array $values, bool $accountsRequired): array
    {
        $errors = [];

        if (!in_array($values['threshold'], self::THRESHOLDS, true)) {
            $errors[] = 'しきい値を選んでください。';
        }

        foreach (self::NUMBERS as $field => [$min, $max, $message]) {
            if (!self::inRange($values[$field], $min, $max)) {
                $errors[] = $message;
            }
        }

        $subjectLength = mb_strlen($values['mail_subject']);

        if ($subjectLength < 1 || $subjectLength > 100) {
            $errors[] = '件名は 1〜100 文字にしてください。';
        }

        if (mb_strlen(trim($values['mail_body'])) < 1 || mb_strlen($values['mail_body']) > 4000) {
            $errors[] = '本文は 1〜4000 文字にしてください。';
        }

        preg_match_all('/\{[^}]*\}/u', $values['mail_subject'] . $values['mail_body'], $found);
        $unknown = array_values(array_unique(array_diff($found[0], NotificationMail::PLACEHOLDERS)));

        if ($unknown !== []) {
            $errors[] = '文面に使えない差し込みがあります：' . implode('、', $unknown);
        }

        $account = $values['smtp_host'] !== '' || $values['smtp_username'] !== '' || $values['smtp_password'] !== '' || $values['mail_from_address'] !== '';

        if (($account || $accountsRequired) && ($values['smtp_host'] === '' || $values['mail_from_address'] === '')) {
            $errors[] = 'SMTP のサーバー名と送信元のアドレスを入れてください。';
        }

        if (!in_array($values['smtp_encryption'], ['ssl', 'starttls'], true)) {
            $errors[] = '暗号化の方式を選んでください。';
        }

        if ($values['mail_from_address'] !== '' && !EmployeeValidator::isAddress($values['mail_from_address'])) {
            $errors[] = '送信元のアドレスの形が正しくありません。';
        }

        $nameLength = mb_strlen($values['mail_from_name']);

        if ($nameLength < 1 || $nameLength > 50) {
            $errors[] = '送信元の名前は 1〜50 文字にしてください。';
        }

        if ($values['imap_host'] === '' && $accountsRequired) {
            $errors[] = '返信を受ける IMAP のサーバー名を入れてください。';
        }

        // The receiving fields matter only once a server is given.
        if ($values['imap_host'] !== '') {
            if (!self::inRange($values['imap_port'], 1, 65535)) {
                $errors[] = 'IMAP のポート番号は 1〜65535 にしてください。';
            }

            if (!in_array($values['imap_encryption'], ['ssl', 'starttls'], true)) {
                $errors[] = '受信の暗号化の方式を選んでください。';
            }
        }

        return $errors;
    }

    /**
     * The settings to save, by setting name. An empty password field keeps the saved password, unless that account's
     * server, user name (and, for sending, sender) were cleared too.
     *
     * @param array<string, string> $values by form field
     *
     * @return array<string, string>
     */
    public static function toSettings(array $values): array
    {
        $saved = [];

        foreach (self::FIELDS as $field => $name) {
            $saved[$name] = $values[$field];
        }

        if ($values['smtp_password'] === '' && ($values['smtp_host'] !== '' || $values['smtp_username'] !== '' || $values['mail_from_address'] !== '')) {
            unset($saved['smtp.password']);
        }

        if ($values['imap_password'] === '' && ($values['imap_host'] !== '' || $values['imap_username'] !== '')) {
            unset($saved['imap.password']);
        }

        return $saved;
    }

    private static function inRange(string $number, int $min, int $max): bool
    {
        return ctype_digit($number) && (int) $number >= $min && (int) $number <= $max;
    }
}
