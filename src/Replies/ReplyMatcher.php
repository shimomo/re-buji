<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Mail\MessageRepository;

/**
 * Finds whose reply a mail is, in the order of design 5.2: the thread (In-Reply-To and References against the
 * Message-IDs we sent), the number in the subject, then the sender's address. Spoofing is accepted (design 5.2).
 *
 * @author shimomo
 */
final class ReplyMatcher
{
    public function __construct(private readonly Database $db, private readonly MessageRepository $messages)
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

        // The person's latest contact that went out and has no answer yet.
        $id = $this->db->fetchValue(
            'SELECT n.id FROM notifications n JOIN employee_emails e ON e.employee_id = n.employee_id'
            . ' WHERE e.address_key = ? AND n.sent_count > 0 AND n.replied_at IS NULL ORDER BY n.last_sent_at DESC, n.id DESC LIMIT 1',
            [EmployeeRepository::addressKey($mail->fromAddress)],
        );

        return $id === null ? null : new ReplyMatch((int) $id, 'sender');
    }
}
