<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Replies\IncomingMail;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.3, 3: setup finishes only once the reply to the test mail has been read from the mailbox.
 *
 * @author shimomo
 */
final class ReplyStep implements InstallStep
{
    public function __construct(private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'reply';
    }

    #[Override]
    public function title(): string
    {
        return '返信の確認';
    }

    #[Override]
    public function template(): string
    {
        return 'reply';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return ['to' => self::text($context->draft()['mail.test_to'] ?? null)];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $draft = $context->draft();
        $messageId = self::text($draft['mail.test_message_id'] ?? null);
        $number = self::text($draft['mail.test_number'] ?? null);
        $since = (string) (new SystemState(Database::fromConfig($context->config(), $context->paths), $this->clock))->get('imap.since');
        $mailbox = $context->container->get(Mailbox::class);
        assert($mailbox instanceof Mailbox);

        try {
            foreach ($mailbox->unseen($since === '' ? $this->clock->now() : Time::parse($since), 50) as $raw) {
                try {
                    $mail = IncomingMail::parse($raw->raw);
                } catch (InvalidArgumentException) {
                    continue;
                }

                if (($messageId !== '' && in_array($messageId, $mail->references, true)) || ($number !== '' && str_contains($mail->subject, '#' . $number))) {
                    $mailbox->markSeen($raw);

                    return StepResult::done();
                }
            }
        } catch (MailboxUnavailable $exception) {
            return StepResult::failed(['メールを受信できませんでした：' . $exception->getMessage()]);
        }

        return StepResult::failed(['まだ返信が見つかりません。返信してから少し待って、もう一度押してください。']);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
