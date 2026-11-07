<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Override;
use ReBuji\Settings\SmtpSettings;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends over SMTP with the organisation's mail account (design 5.1). The sender, Reply-To and the envelope sender
 * (Return-Path) are all the account's address, so replies and bounces come back to the mailbox read over IMAP.
 *
 * One instance keeps its connection between mails. Connecting and reading time out after PHP's default_socket_timeout
 * (60 seconds unless the server changed it).
 *
 * @author shimomo
 */
final class SmtpMailSender implements MailSender
{
    private ?RecipientAwareSmtpTransport $transport = null;

    public function __construct(private readonly ?SmtpSettings $settings)
    {
        //
    }

    #[Override]
    public function send(OutgoingMail $mail): void
    {
        if ($this->settings === null) {
            throw new MailUnavailable('メールの送信の設定がありません。「設定」で SMTP を登録してください。');
        }

        $recipient = MailAddress::forSending($mail->to);

        try {
            $from = new Address($mail->fromAddress, $mail->fromName);
            $email = (new Email())
                ->from($from)
                ->to($recipient)
                ->replyTo(new Address($mail->fromAddress))
                ->subject($mail->subject)
                ->text($mail->body, 'utf-8');
            $email->getHeaders()->addIdHeader('Message-ID', $mail->messageId);
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

            $this->transport()->send($email, new Envelope(new Address($mail->fromAddress), [$recipient]));
        } catch (RecipientRefusedResponse $exception) {
            throw new RecipientRefused($exception->getMessage(), 0, $exception);
        } catch (RecipientDeferredResponse $exception) {
            throw new RecipientDeferred($exception->getMessage(), 0, $exception);
        } catch (TransportExceptionInterface|RfcComplianceException $exception) {
            throw new MailUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    private function transport(): RecipientAwareSmtpTransport
    {
        if ($this->transport === null) {
            assert($this->settings !== null);
            $transport = new RecipientAwareSmtpTransport($this->settings->host, $this->settings->port, $this->settings->encryption === 'ssl');

            if ($this->settings->username !== '') {
                $transport->setUsername($this->settings->username);
                $transport->setPassword($this->settings->password);
            }

            $this->transport = $transport;
        }

        return $this->transport;
    }
}
