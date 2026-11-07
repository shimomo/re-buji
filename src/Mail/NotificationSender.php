<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReBuji\Employees\Employee;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;

/**
 * Sends contacts within the hourly limit (design 5.3), one mail per address.
 *
 * A person's mails go out together or wait together: when the limit cannot take all of them, the run stops there and
 * the rest wait for the next run. A server that cannot be reached stops the run too; nothing is marked as not
 * delivered for it.
 *
 * @author shimomo
 */
final class NotificationSender
{
    /**
     * A run stops moving on to the next person after this long: rental servers may kill a heavy cron (design 6.1).
     */
    public const int MAX_SECONDS = 120;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EarthquakeRepository $earthquakes,
        private readonly EmployeeRepository $employees,
        private readonly MessageRepository $messages,
        private readonly RateLimit $limit,
        private readonly NotificationMail $mails,
        private readonly MailSender $sender,
        private readonly Settings $settings,
        private readonly SystemState $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    public function sendFirstContacts(): void
    {
        $run = $this->start();

        foreach ($this->notifications->dueFirstContacts($run->now) as $contact) {
            $employee = $this->recipient($contact);

            if ($employee === null) {
                $this->notifications->markSkipped($contact->id);

                continue;
            }

            $delivery = $this->deliver($run, $contact, NotificationMail::FIRST, $employee->emails);

            if ($delivery === Delivery::Postponed) {
                break;
            }

            if ($delivery === Delivery::Refused) {
                $this->notifications->markFailed($contact->id);
            } elseif ($delivery !== Delivery::Unavailable) {
                $this->notifications->markSent($contact->id, $run->now, $this->nextResend($run->now, 1));
            }

            if ($run->unavailable) {
                break;
            }
        }

        $this->finish($run);
    }

    public function sendCancelNotices(): void
    {
        $run = $this->start();

        foreach ($this->notifications->dueCancelNotices() as $contact) {
            $delivery = $this->deliver($run, $contact, NotificationMail::CANCEL, $this->messages->sentAddresses($contact->id));

            if ($delivery === Delivery::Postponed || $delivery === Delivery::Unavailable) {
                break;
            }

            $this->notifications->markCancelNoticeSent($contact->id);

            if ($run->unavailable) {
                break;
            }
        }

        $this->finish($run);
    }

    public function sendResends(): void
    {
        $run = $this->start();

        foreach ($this->notifications->dueResends($run->now) as $contact) {
            $employee = $this->recipient($contact);
            $refused = array_map(EmployeeRepository::addressKey(...), $this->messages->refusedAddresses($contact->id));
            $addresses = $employee === null ? [] : array_values(array_filter(
                $employee->emails,
                static fn(string $address): bool => !in_array(EmployeeRepository::addressKey($address), $refused, true),
            ));

            if ($addresses === []) {
                $this->notifications->stopResending($contact->id);

                continue;
            }

            $delivery = $this->deliver($run, $contact, NotificationMail::RESEND, $addresses);

            if ($delivery === Delivery::Postponed) {
                break;
            }

            if ($delivery === Delivery::Refused) {
                $this->notifications->stopResending($contact->id);
            } elseif ($delivery !== Delivery::Unavailable) {
                $this->notifications->markSent($contact->id, $run->now, $this->nextResend($run->now, $contact->sentCount + 1));
            }

            if ($run->unavailable) {
                break;
            }
        }

        $this->finish($run);
    }

    private function start(): SendingRun
    {
        return new SendingRun($this->clock->now());
    }

    /**
     * Sends one person's mails, or none of them when the limit or the time is up.
     *
     * @param list<string> $addresses
     */
    private function deliver(SendingRun $run, Notification $contact, string $kind, array $addresses): Delivery
    {
        if ($addresses === []) {
            return Delivery::Refused;
        }

        if ($this->clock->now()->getTimestamp() - $run->now->getTimestamp() > self::MAX_SECONDS || $this->limit->remaining($run->now) < count($addresses)) {
            return Delivery::Postponed;
        }

        $quake = $this->earthquakes->find($contact->earthquakeId);

        if ($quake === null) {
            return Delivery::Refused;
        }

        $sent = 0;

        foreach ($addresses as $address) {
            try {
                $mail = $this->mails->compose($quake, $contact, $kind, $address);

                try {
                    $this->sender->send($mail);
                } catch (RecipientRefused $exception) {
                    $this->messages->record($contact->id, $kind, $address, $mail->messageId, false, $exception->getMessage(), $run->now);
                    $this->logger->warning('mail: ' . $address . ' refused: ' . $exception->getMessage());

                    continue;
                }

                $this->messages->record($contact->id, $kind, $address, $mail->messageId, true, '', $run->now);
                $sent++;
                $run->succeeded = true;
            } catch (MailUnavailable $exception) {
                $this->state->set('mail.last_error', $exception->getMessage());
                $this->logger->error('mail: cannot send: ' . $exception->getMessage());
                $run->unavailable = true;

                break;
            }
        }

        if ($sent > 0) {
            return Delivery::Sent;
        }

        return $run->unavailable ? Delivery::Unavailable : Delivery::Refused;
    }

    private function nextResend(DateTimeImmutable $now, int $sentCount): ?DateTimeImmutable
    {
        if ($sentCount >= 1 + $this->settings->int('resend_count')) {
            return null;
        }

        return $now->modify('+' . $this->settings->int('resend_interval_minutes') . ' minutes');
    }

    /**
     * The person to send to now, or null when they were deleted or stopped since the contact was made.
     */
    private function recipient(Notification $contact): ?Employee
    {
        $employee = $contact->employeeId === null ? null : $this->employees->find($contact->employeeId);

        return $employee !== null && $employee->active ? $employee : null;
    }

    private function finish(SendingRun $run): void
    {
        if ($run->succeeded && !$run->unavailable) {
            $this->state->set('mail.last_error', '');
        }
    }
}
