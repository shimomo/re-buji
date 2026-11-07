<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Mail\MessageRepository;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;

/**
 * Records what a mail from the reply mailbox means for the contacts (design 5.2, 5.5).
 *
 * @author shimomo
 */
final class ReplyRecorder
{
    /**
     * How much of an unmatched mail is kept for the admins to read.
     */
    public const int UNMATCHED_LENGTH = 2000;

    public function __construct(
        private readonly Database $db,
        private readonly ReplyMatcher $matcher,
        private readonly ReplyRepository $replies,
        private readonly NotificationRepository $notifications,
        private readonly MessageRepository $messages,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    public function record(IncomingMail $mail): void
    {
        $this->db->transaction(function () use ($mail): void {
            if ($mail->bounce !== null) {
                $this->recordBounce($mail, $mail->bounce);

                return;
            }

            $now = $this->clock->now();
            $match = $this->matcher->match($mail);
            $receivedAt = $mail->date ?? $now;

            if ($match === null) {
                $this->replies->record(null, $mail->kind(), null, $mail->fromAddress, $mail->subject, mb_substr($mail->text, 0, self::UNMATCHED_LENGTH), $mail->messageId, $receivedAt);

                return;
            }

            if ($mail->autoReply) {
                $this->notifications->recordAutoReply($match->notificationId, $now);
                $this->replies->record($match->notificationId, IncomingMail::AUTO_REPLY, $match->by, $mail->fromAddress, $mail->subject, mb_substr($mail->text, 0, self::UNMATCHED_LENGTH), $mail->messageId, $receivedAt);

                return;
            }

            // Empty means safe; anything written is for a person to read (design 5.6: no sorting by words).
            $body = ReplyText::extract($mail->text);
            $this->notifications->recordAnswer($match->notificationId, $body === '' ? Notification::SAFE : Notification::NEEDS_CHECK, 'mail', null, null, $receivedAt);
            $this->replies->record($match->notificationId, IncomingMail::REPLY, $match->by, $mail->fromAddress, $mail->subject, $body, $mail->messageId, $receivedAt);
        });
    }

    /**
     * Marks the returned mail's address as not delivered. A contact with no address left that got through is not
     * delivered either, unless the person answered anyway.
     */
    private function recordBounce(IncomingMail $mail, Bounce $bounce): void
    {
        $now = $this->clock->now();
        $returned = [];
        $by = 'thread';
        $original = $bounce->originalMessageId === null ? null : $this->messages->findByMessageId($bounce->originalMessageId);

        if ($original !== null) {
            $returned[] = $original;
        } else {
            $by = 'sender';

            foreach ($bounce->recipients as $recipient) {
                $sent = $this->messages->latestSentTo($recipient);

                if ($sent !== null) {
                    $returned[] = $sent;
                }
            }
        }

        $contacts = [];

        foreach ($returned as $sent) {
            $this->messages->markBounced($sent['id'], $bounce->reason, $now);

            if ($sent['notificationId'] !== null) {
                $contacts[$sent['notificationId']] = true;
            }
        }

        foreach (array_keys($contacts) as $id) {
            $contact = $this->notifications->find($id);

            if ($contact !== null && $contact->state === Notification::SENT && $contact->answer === null && $this->messages->deliveredAddresses($id) === []) {
                $this->notifications->markFailed($id);
            }
        }

        $first = array_key_first($contacts);
        $this->replies->record($first, IncomingMail::BOUNCE, $first === null ? null : $by, $mail->fromAddress, $mail->subject, mb_substr($mail->text, 0, self::UNMATCHED_LENGTH), $mail->messageId, $mail->date ?? $now);
    }
}
