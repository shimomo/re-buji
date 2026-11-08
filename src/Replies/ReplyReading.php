<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeImmutable;
use DateTimeZone;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Whether replies are being read. A run that dies without a word (out of memory, killed by the host) leaves no error
 * behind, only a last read that grows old; after STALE_MINUTES the home page and the watch mail say so.
 *
 * @author shimomo
 */
final class ReplyReading
{
    public const int STALE_MINUTES = 30;

    public function __construct(private readonly Settings $settings, private readonly SystemState $state)
    {
        //
    }

    /**
     * The warning, or null while replies are read (or when IMAP is missing or failing: those have their own words).
     */
    public function staleWarning(DateTimeImmutable $now): ?string
    {
        if ($this->settings->imap() === null || (string) $this->state->get('imap.last_error') !== '') {
            return null;
        }

        $last = (string) $this->state->get('imap.last_checked_at');
        $since = $last !== '' ? $last : (string) $this->state->get('imap.since');

        if ($since === '' || $now->getTimestamp() - Time::parse($since)->getTimestamp() <= self::STALE_MINUTES * 60) {
            return null;
        }

        $when = $last === ''
            ? 'まだ一度も読めていません'
            : '最後は ' . Time::parse($last)->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G時i分');

        return '返信を ' . self::STALE_MINUTES . ' 分以上読めていません（' . $when . '）。';
    }
}
