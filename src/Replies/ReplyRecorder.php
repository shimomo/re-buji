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

            // Empty means safe; anything written is for a person to read (design 5.6: no sorting by words). Words in a
            // rewritten subject and attached files count as written: a photo of the damage is not "safe".
            $written = [];
            $subject = $this->rewrittenSubject($mail->subject, $match->notificationId);

            if ($subject !== null) {
                $written[] = '件名：' . $subject;
            }

            if ($mail->attachments !== []) {
                $written[] = '添付ファイル：' . implode('、', $mail->attachments);
            }

            $text = ReplyText::extract($mail->text);

            if ($text !== '') {
                $written[] = $text;
            }

            $body = implode("\n\n", $written);
            $this->notifications->recordAnswer($match->notificationId, $body === '' ? Notification::SAFE : Notification::NEEDS_CHECK, 'mail', null, null, $receivedAt);
            $this->replies->record($match->notificationId, IncomingMail::REPLY, $match->by, $mail->fromAddress, $mail->subject, $body, $mail->messageId, $receivedAt);
        });
    }

    /**
     * The reply's subject when the person wrote something in it, or null when it is ours with "Re:" and the like.
     * Without a subject on record for the contact, there is nothing to compare with.
     */
    private function rewrittenSubject(string $subject, int $notificationId): ?string
    {
        $sent = array_map(self::bareSubject(...), $this->messages->sentSubjects($notificationId));
        $bare = self::bareSubject($subject);

        return $sent === [] || $bare === '' || in_array($bare, $sent, true) ? null : trim($subject);
    }

    private static function bareSubject(string $subject): string
    {
        $spaced = preg_replace('/[\s\x{3000}]+/u', ' ', $subject) ?? $subject;
        $bare = preg_replace('/^\s*(?:(?:re|fw|fwd|aw|sv|tr|返信|転送)\s*(?:\[\d+\]|\(\d+\))?\s*[:：]\s*)+/iu', '', $spaced) ?? $spaced;

        return trim($bare);
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
            // A delay is news, not a failure: the mail system is still trying, and resending goes on.
            if ($bounce->failed) {
                $this->messages->markBounced($sent['id'], $bounce->reason, $now);
            }

            if ($sent['notificationId'] !== null) {
                $contacts[$sent['notificationId']] = true;
            }
        }

        foreach ($bounce->failed ? array_keys($contacts) : [] as $id) {
            $contact = $this->notifications->find($id);

            if ($contact !== null && $contact->state === Notification::SENT && $contact->answer === null && $this->messages->deliveredAddresses($id) === []) {
                $this->notifications->markFailed($id);
            }
        }

        $first = array_key_first($contacts);
        $this->replies->record($first, $mail->kind(), $first === null ? null : $by, $mail->fromAddress, $mail->subject, mb_substr($mail->text, 0, self::UNMATCHED_LENGTH), $mail->messageId, $mail->date ?? $now);
    }
}
