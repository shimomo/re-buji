<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use Psr\Clock\ClockInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Database\Database;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\RecipientRefused;
use ReBuji\Settings\Settings;
use ReBuji\Settings\SettingsForm;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.3, 2: the sending and receiving accounts, the hourly limit and the threshold. The step logs in to the
 * mailbox, then sends the first admin a test mail; the next step waits for its reply.
 *
 * @author shimomo
 */
final class MailStep implements InstallStep
{
    /**
     * The fields this step shows; the others keep their defaults (the settings page has them all).
     */
    public const array FIELDS = [
        'threshold', 'hourly_limit', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'mail_from_address', 'mail_from_name', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
    ];

    public function __construct(private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'mail';
    }

    #[Override]
    public function title(): string
    {
        return 'メールの設定';
    }

    #[Override]
    public function template(): string
    {
        return 'mail';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return ['values' => self::strings($context->draft()['mail.values'] ?? null) + self::current($this->settings($context))];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $db = $this->database($context);
        $settings = new Settings($db, $this->clock);
        $values = SettingsForm::read(array_intersect_key($input, array_flip(self::FIELDS)) + self::current($settings));
        $context->putDraft('mail.values', array_diff_key($values, ['smtp_password' => true, 'imap_password' => true]));
        $errors = SettingsForm::validate($values, true);

        if ($errors !== []) {
            return StepResult::failed($errors);
        }

        $settings->save(SettingsForm::toSettings($values));
        (new SystemState($db, $this->clock))->set('imap.since', Time::format($this->clock->now()));
        $mailbox = $context->container->get(Mailbox::class);
        $sender = $context->container->get(MailSender::class);
        assert($mailbox instanceof Mailbox && $sender instanceof MailSender);

        try {
            $mailbox->unseen($this->clock->now(), 1);
        } catch (MailboxUnavailable $exception) {
            return StepResult::failed(['メールを受信できませんでした：' . $exception->getMessage()]);
        }

        $admin = (new AdminRepository($db, $this->clock))->all()[0] ?? null;

        if ($admin === null) {
            return StepResult::failed(['最初の管理者がいません。前の段階に戻ってください。']);
        }

        $number = bin2hex(random_bytes(4));

        try {
            $mail = (new NotificationMail($settings, $this->clock))->setupMail($admin['email'], $admin['name'], $number);
            $sender->send($mail);
        } catch (RecipientRefused|MailUnavailable $exception) {
            return StepResult::failed(['テストメールを送れませんでした：' . $exception->getMessage()]);
        }

        (new MessageRepository($db))->record(null, 'test', $admin['email'], $mail->messageId, true, '', $this->clock->now());
        $context->putDraft('mail.test_message_id', $mail->messageId);
        $context->putDraft('mail.test_number', $number);
        $context->putDraft('mail.test_to', $admin['email']);

        return StepResult::done();
    }

    /**
     * @return array<string, string> the saved settings by form field, passwords left out
     */
    private static function current(Settings $settings): array
    {
        $values = [];

        foreach (SettingsForm::FIELDS as $field => $name) {
            $values[$field] = in_array($field, ['smtp_password', 'imap_password'], true) ? '' : $settings->get($name);
        }

        return $values;
    }

    /**
     * @return array<string, string> the text values of a draft, or nothing
     */
    private static function strings(mixed $values): array
    {
        $strings = [];

        foreach (is_array($values) ? array_filter($values, 'is_string') : [] as $key => $value) {
            $strings[(string) $key] = $value;
        }

        return $strings;
    }

    private function settings(InstallContext $context): Settings
    {
        return new Settings($this->database($context), $this->clock);
    }

    private function database(InstallContext $context): Database
    {
        return Database::fromConfig($context->config(), $context->paths);
    }
}
