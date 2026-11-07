<?php

declare(strict_types=1);

namespace ReBuji\Mail;

/**
 * Sends one mail. The way out to people (design 5.7): the rest of the app only knows this.
 *
 * @author shimomo
 */
interface MailSender
{
    /**
     * @throws RecipientRefused when only this recipient was refused (not retried)
     * @throws RecipientDeferred when only this recipient was refused for now (retried later, the others go on)
     * @throws MailUnavailable for anything else (retried next run)
     */
    public function send(OutgoingMail $mail): void;
}
