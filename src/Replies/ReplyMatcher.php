<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Mail\MessageRepository;
use ReBuji\Time;

/**
 * Finds whose reply a mail is, in the order of design 5.2: the thread (In-Reply-To and References against the
 * Message-IDs we sent), the number in the subject, then the sender's address. Spoofing is accepted (design 5.2).
 *
 * @author shimomo
 */
final class ReplyMatcher
{
    /**
     * By the sender's address alone, a mail is tied only to a contact sent this recently: months later, a mail from
     * the same person is about something else.
     */
    public const int SENDER_DAYS = 7;

    public function __construct(private readonly Database $db, private readonly MessageRepository $messages, private readonly ClockInterface $clock)
    {
        //
    }

    public function match(IncomingMail $mail): ?ReplyMatch
    {
        foreach ($mail->references as $id) {
            $sent = $this->messages->findByMessageId($id);

            if ($sent !== null && $sent['notificationId'] !== null) {
                return new ReplyMatch($sent['notificationId'], 'thread');
            }
        }

        if (preg_match('/#([0-9a-f]{8})\b/', $mail->subject, $match) === 1) {
            $id = $this->db->fetchValue('SELECT id FROM notifications WHERE token = ?', [$match[1]]);

            if ($id !== null) {
                return new ReplyMatch((int) $id, 'number');
            }
        }

        if ($mail->fromAddress === '') {
            return null;
        }

        // The person's latest contact that went out within SENDER_DAYS and has no answer yet.
        $id = $this->db->fetchValue(
            'SELECT n.id FROM notifications n JOIN employee_emails e ON e.employee_id = n.employee_id'
            . ' WHERE e.address_key = ? AND n.sent_count > 0 AND n.replied_at IS NULL AND n.last_sent_at >= ? ORDER BY n.last_sent_at DESC, n.id DESC LIMIT 1',
            [EmployeeRepository::addressKey($mail->fromAddress), Time::format($this->clock->now()->modify('-' . self::SENDER_DAYS . ' days'))],
        );

        return $id === null ? null : new ReplyMatch((int) $id, 'sender');
    }
}
