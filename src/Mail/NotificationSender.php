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

    /**
     * A person whose addresses were all refused for now is tried again after this long, behind the others.
     */
    public const int DEFER_MINUTES = 15;

    /**
     * After this many people refused for now one after another, the server is likely refusing everyone: stop the step.
     */
    public const int DEFERRALS_IN_A_ROW = 3;

    /**
     * The server could not be used in this cron run (one instance serves a whole run: SendJob and ResendJob share it).
     */
    private bool $unavailable = false;

    /**
     * A sending problem was shown in this cron run.
     */
    private bool $troubled = false;

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

        if ($run === null) {
            return;
        }

        foreach ($this->notifications->dueFirstContacts($run->now) as $contact) {
            $employee = $this->recipient($contact);

            if ($employee === null) {
                $this->notifications->markSkipped($contact->id);

                continue;
            }

            $delivery = $this->deliver($run, $contact, NotificationMail::FIRST, $employee->emails);

            match ($delivery) {
                Delivery::Postponed, Delivery::Unavailable => null,
                Delivery::Sent => $this->notifications->markSent($contact->id, $run->now, $this->nextResend($run->now, 1)),
                Delivery::Refused => $this->notifications->markFailed($contact->id),
                Delivery::Deferred => $this->notifications->postpone($contact->id, $run->now->modify('+' . self::DEFER_MINUTES . ' minutes')),
                Delivery::Withdrawn => $this->notifications->markCancelled($contact->id),
            };

            if ($run->mustStop()) {
                break;
            }
        }

        $this->finish($run);
    }

    public function sendCancelNotices(): void
    {
        $run = $this->start();

        if ($run === null) {
            return;
        }

        foreach ($this->notifications->dueCancelNotices() as $contact) {
            $delivery = $this->deliver($run, $contact, NotificationMail::CANCEL, $this->messages->sentAddresses($contact->id));

            // A notice refused for now is tried again next run, like one the server could not take.
            if ($delivery !== Delivery::Postponed && $delivery !== Delivery::Unavailable && $delivery !== Delivery::Deferred) {
                $this->notifications->markCancelNoticeSent($contact->id);
            }

            if ($run->mustStop()) {
                break;
            }
        }

        $this->finish($run);
    }

    public function sendResends(): void
    {
        $run = $this->start();

        if ($run === null) {
            return;
        }

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

            match ($delivery) {
                Delivery::Postponed, Delivery::Unavailable => null,
                Delivery::Sent => $this->notifications->markSent($contact->id, $run->now, $this->nextResend($run->now, $contact->sentCount + 1)),
                Delivery::Refused, Delivery::Withdrawn => $this->notifications->stopResending($contact->id),
                Delivery::Deferred => $this->notifications->postponeResend($contact->id, $run->now->modify('+' . self::DEFER_MINUTES . ' minutes')),
            };

            if ($run->mustStop()) {
                break;
            }
        }

        $this->finish($run);
    }

    /**
     * A new step of the cron run, or null once the server proved unavailable in an earlier step: trying again would
     * only wait for the same timeout.
     */
    private function start(): ?SendingRun
    {
        return $this->unavailable ? null : new SendingRun($this->clock->now());
    }

    /**
     * Sends one person's mails, or none of them when the limit or the time is up.
     *
     * @param list<string> $addresses
     */
    private function deliver(SendingRun $run, Notification $contact, string $kind, array $addresses): Delivery
    {
        $quake = $this->earthquakes->find($contact->earthquakeId);

        // Whatever state the contact is in, nothing but the cancel notice goes out for a cancelled quake.
        if ($quake !== null && $quake->isCancelled() && $kind !== NotificationMail::CANCEL) {
            return Delivery::Withdrawn;
        }

        if ($quake === null || $addresses === []) {
            return Delivery::Refused;
        }

        if ($this->clock->now()->getTimestamp() - $run->now->getTimestamp() > self::MAX_SECONDS || $this->limit->remaining($run->now) < count($addresses)) {
            $run->postponed = true;

            return Delivery::Postponed;
        }

        $sent = 0;
        $deferred = 0;

        foreach ($addresses as $address) {
            try {
                $mail = $this->mails->compose($quake, $contact, $kind, $address);

                try {
                    $this->sender->send($mail);
                } catch (RecipientRefused $exception) {
                    $this->messages->record($contact->id, $kind, $address, $mail->messageId, false, $exception->getMessage(), $run->now, $mail->subject);
                    $this->logger->warning('mail: ' . $address . ' refused: ' . $exception->getMessage());

                    continue;
                } catch (RecipientDeferred $exception) {
                    $this->trouble('一時的に送れない宛先があります：' . $address . '（' . $exception->getMessage() . '）');
                    $this->logger->warning('mail: ' . $address . ' deferred: ' . $exception->getMessage());
                    $deferred++;

                    continue;
                }

                $this->messages->record($contact->id, $kind, $address, $mail->messageId, true, '', $run->now, $mail->subject);
                $sent++;
                $run->succeeded = true;
            } catch (MailUnavailable $exception) {
                $this->trouble($exception->getMessage());
                $this->logger->error('mail: cannot send: ' . $exception->getMessage());
                $this->unavailable = true;
                $run->unavailable = true;

                break;
            }
        }

        $run->deferredInARow = $sent === 0 && $deferred > 0 && !$run->unavailable ? $run->deferredInARow + 1 : 0;

        return match (true) {
            $sent > 0 => Delivery::Sent,
            $run->unavailable => Delivery::Unavailable,
            $deferred > 0 => Delivery::Deferred,
            default => Delivery::Refused,
        };
    }

    /**
     * Shows a sending problem on the home page. It stays for the rest of the cron run, so a later step that goes well
     * does not hide it.
     */
    private function trouble(string $message): void
    {
        $this->state->set('mail.last_error', $message);
        $this->troubled = true;
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
        if ($run->succeeded && !$this->troubled) {
            $this->state->set('mail.last_error', '');
        }
    }
}
