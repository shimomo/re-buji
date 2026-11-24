<?php

declare(strict_types=1);

namespace ReBuji\Install;

use ReBuji\Console\ConsoleIo;
use ReBuji\Settings\Settings;
use ReBuji\Settings\SettingsForm;

/**
 * The mail step's questions as bin/console install asks them: only the settings the settings file leaves out, in the
 * order of the form, each with a default that Enter alone accepts.
 *
 * @author shimomo
 */
final class MailQuestions
{
    /**
     * By form field: the heading each question comes under, and the question.
     */
    private const array QUESTIONS = [
        'mail_from_address' => ['送信のメール（SMTP）', '  送信元のアドレス'],
        'smtp_host' => ['送信のメール（SMTP）', '  サーバー名'],
        'smtp_port' => ['送信のメール（SMTP）', '  ポート'],
        'smtp_encryption' => ['送信のメール（SMTP）', '  暗号化'],
        'smtp_username' => ['送信のメール（SMTP）', '  ユーザー名'],
        'smtp_password' => ['送信のメール（SMTP）', '  パスワード'],
        'imap_host' => ['受信のメール（IMAP）', '  サーバー名'],
        'imap_port' => ['受信のメール（IMAP）', '  ポート'],
        'imap_encryption' => ['受信のメール（IMAP）', '  暗号化'],
        'imap_username' => ['受信のメール（IMAP）', '  ユーザー名'],
        'imap_password' => ['受信のメール（IMAP）', '  パスワード'],
    ];

    /**
     * Asks what $file leaves out. A heading shows only above a question.
     *
     * @param array<string, string> $file the settings file's values, by form field
     * @param array<string, string> $previous the answers of the try before, which Enter keeps
     *
     * @return array<string, string> the answers, by form field
     */
    public static function ask(Settings $settings, array $file, array $previous, ConsoleIo $io): array
    {
        $values = $file;
        $answers = [];
        $heading = '';

        foreach (self::QUESTIONS as $field => [$section, $question]) {
            // Without a user name of its own, the mailbox logs in as the sending account, password and all.
            if (isset($file[$field]) || ($field === 'imap_password' && ($values['imap_username'] ?? '') === '')) {
                continue;
            }

            [$shown, $default] = self::default($field, $previous[$field] ?? null, $settings->get(SettingsForm::FIELDS[$field]), $values);

            if ($section !== $heading) {
                $io->line($section);
                $heading = $section;
            }

            $prompt = $question . ($shown === '' ? '' : ' [' . $shown . ']') . '：';
            $answer = str_ends_with($field, '_password') ? $io->secret($prompt) : $io->ask($prompt);
            $values[$field] = $answers[$field] = $answer === '' ? $default : $answer;
        }

        return $answers;
    }

    /**
     * What Enter alone answers for $field, and how its question shows that ('' for nothing).
     *
     * @param ?string $previous the answer of the try before
     * @param string $saved the saved setting, or its default
     * @param array<string, string> $values the fields known so far
     *
     * @return array{string, string} what is shown, and the answer
     */
    private static function default(string $field, ?string $previous, string $saved, array $values): array
    {
        // An empty password keeps the saved one (MailStep).
        if (str_ends_with($field, '_password')) {
            return ($previous ?? '') !== '' || $saved !== '' ? ['前のまま', $previous ?? ''] : ['', ''];
        }

        $known = $previous ?? $saved;
        $default = match (true) {
            $known !== '' => $known,
            $field === 'smtp_username' => $values['mail_from_address'] ?? '',
            $field === 'imap_host' => $values['smtp_host'] ?? '',
            default => '',
        };

        return $field === 'imap_username' && $default === '' ? ['送信と同じ', ''] : [$default, $default];
    }
}
