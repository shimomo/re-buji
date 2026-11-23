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
 * Symfony reads PHP's default_socket_timeout when it connects, so send() and logIn() set it for the time they talk to
 * the server and put the server's value back; the socket class's own setter is internal to Symfony.
 *
 * A failure before the log-in was through is an AccountUnavailable; logIn() goes only that far.
 *
 * @author shimomo
 */
final class SmtpMailSender implements MailSender, MailLogin
{
    public const int TIMEOUT_SECONDS = 15;

    public const int CRON_TIMEOUT_SECONDS = 60;

    private ?RecipientAwareSmtpTransport $transport = null;

    /**
     * @param SendingAccount|null $account told whether each use of the account worked (none in tests that look only at
     *                                     the wire)
     */
    public function __construct(
        private readonly ?SmtpSettings $settings,
        public readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        private readonly ?SendingAccount $account = null,
    ) {
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
        } catch (AccountUnavailableResponse $exception) {
            $this->account?->failed($exception->getMessage());

            throw new AccountUnavailable($exception->getMessage(), 0, $exception);
        } catch (RecipientRefusedResponse $exception) {
            $this->account?->worked();

            throw new RecipientRefused($exception->getMessage(), 0, $exception);
        } catch (RecipientDeferredResponse $exception) {
            $this->account?->worked();

            throw new RecipientDeferred($exception->getMessage(), 0, $exception);
        } catch (TransportExceptionInterface $exception) {
            // Past the log-in (start() would have said otherwise): the sender or the body was refused, or the answer
            // after the body never came. The account itself works.
            $this->account?->worked();

            throw new MailUnavailable($exception->getMessage(), 0, $exception);
        } catch (RfcComplianceException $exception) {
            // The mail could not be put together: nothing reached the server.
            throw new MailUnavailable($exception->getMessage(), 0, $exception);
        } finally {
            if ($timeout !== false) {
                ini_set('default_socket_timeout', $timeout);
            }
        }

        $this->account?->worked();
    }

    /**
     * Connects, starts TLS and logs in as the settings say, then quits without sending. A connection kept from an earlier
     * mail is closed first: it logged in long ago, and proves nothing now.
     */
    #[Override]
    public function logIn(): void
    {
        if ($this->settings === null) {
            throw new MailUnavailable('メールの送信の設定がありません。「設定」で SMTP を登録してください。');
        }

        $timeout = ini_set('default_socket_timeout', (string) $this->timeoutSeconds);

        try {
            $transport = $this->transport();
            $transport->stop();
            $transport->start();
            $transport->stop();
        } catch (AccountUnavailableResponse $exception) {
            $this->account?->failed($exception->getMessage());

            throw new AccountUnavailable($exception->getMessage(), 0, $exception);
        } finally {
            if ($timeout !== false) {
                ini_set('default_socket_timeout', $timeout);
            }
        }

        $this->account?->worked();
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
