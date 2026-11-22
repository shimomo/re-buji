<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Override;
use ReBuji\Settings\SmtpSettings;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends over SMTP with the organisation's mail account (design 5.1). The sender, Reply-To and the envelope sender
 * (Return-Path) are all the account's address, so replies and bounces come back to the mailbox read over IMAP.
 *
 * One instance keeps its connection between mails. Connecting and every reply time out after $timeoutSeconds:
 * - TIMEOUT_SECONDS for a page (a test mail), so the admin gets an answer before the web server or its proxy gives up
 * - CRON_TIMEOUT_SECONDS for cron (Container::forCron()). The same limit covers the server's answer after the mail
 *   body, which a busy server (scanning for viruses, loaded after a quake) may take long to give. Taking such a server
 *   for a dead one would send the first person's mail again every run and nobody else's, while waiting on a server
 *   that is really down only delays the run.
 * A server that stops in the middle of a mail can take about twice the limit: the QUIT after the failure waits too.
 *
 * Symfony reads PHP's default_socket_timeout when it connects, so send() sets it for the time it sends and puts the
 * server's value back; the socket class's own setter is internal to Symfony.
 *
 * @author shimomo
 */
final class SmtpMailSender implements MailSender
{
    public const int TIMEOUT_SECONDS = 15;

    public const int CRON_TIMEOUT_SECONDS = 60;

    private ?RecipientAwareSmtpTransport $transport = null;

    public function __construct(private readonly ?SmtpSettings $settings, public readonly int $timeoutSeconds = self::TIMEOUT_SECONDS)
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
        $timeout = ini_set('default_socket_timeout', (string) $this->timeoutSeconds);

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
        } finally {
            if ($timeout !== false) {
                ini_set('default_socket_timeout', $timeout);
            }
        }
    }

    private function transport(): RecipientAwareSmtpTransport
    {
        if ($this->transport === null) {
            assert($this->settings !== null);
            $transport = new RecipientAwareSmtpTransport($this->settings->host, $this->settings->port, $this->settings->encryption === 'ssl');

            // Without TLS the password would cross the network in clear text: refuse a server that does not offer
            // STARTTLS. 'none' is only for the test servers.
            $transport->setRequireTls($this->settings->encryption === 'starttls');

            // An extra CA to trust, for a mail server with a private certificate. TLS stays on and the name is still checked.
            if ($this->settings->caFile !== '') {
                $stream = $transport->getStream();
                assert($stream instanceof SocketStream);
                /** @psalm-suppress InternalMethod */
                $stream->setStreamOptions(['ssl' => ['cafile' => $this->settings->caFile]]);
            }

            if ($this->settings->username !== '') {
                $transport->setUsername($this->settings->username);
                $transport->setPassword($this->settings->password);
            }

            $this->transport = $transport;
        }

        return $this->transport;
    }
}
