<?php

declare(strict_types=1);

namespace ReBuji\Quakes\Board;

use DateTimeImmutable;
use DateTimeZone;
use JmaXml\Earthquake\SeismicIntensity;
use Psr\Clock\ClockInterface;
use ReBuji\Mail\RateLimit;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Replies\IncomingMail;
use ReBuji\Replies\ReplyRepository;

/**
 * What the manager screen shows of a quake (design 5.6): who has not replied and why, who needs checking with what
 * they wrote, and who is safe. A person folded into a related quake's contact shows that contact's answer.
 *
 * @author shimomo
 */
final class QuakeBoard
{
    /**
     * Problems first: the reasons in the order an admin should act on them.
     */
    private const array REASON_ORDER = ['failed' => 0, 'auto' => 1, 'sent' => 2, 'pending' => 3, 'other' => 4];

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EarthquakeRepository $earthquakes,
        private readonly ReplyRepository $replies,
        private readonly RateLimit $limit,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    /**
     * @return array{noReply: list<BoardRow>, needsCheck: list<BoardRow>, checked: list<BoardRow>, safe: list<BoardRow>, total: int}
     */
    public function build(Earthquake $quake): array
    {
        $now = $this->clock->now();
        $nextSlot = $this->limit->nextSlotAt($now);
        $board = ['noReply' => [], 'needsCheck' => [], 'checked' => [], 'safe' => [], 'total' => 0];
        $waiting = [];

        foreach ($this->notifications->forEarthquake($quake->id) as $contact) {
            if ($contact->state === Notification::SKIPPED) {
                continue;
            }

            $board['total']++;
            [$answering, $foldedInto] = $this->answering($contact);
            [$reason, $group] = self::reason($answering, $now, $nextSlot);
            $row = new BoardRow(
                $answering->id,
                $contact->employeeName,
                $contact->employeeDepartment,
                self::places($contact->cities),
                $reason,
                $answering->repliedAt === null ? null : self::time($answering->repliedAt),
                $this->bodies($answering),
                $answering->answeredBy === null ? null : $answering->answeredBy . ' さんが代理で記録',
                $foldedInto,
            );

            if ($answering->answer === Notification::NEEDS_CHECK) {
                $board[$answering->checkedAt === null ? 'needsCheck' : 'checked'][] = $row;
            } elseif ($answering->answer === Notification::SAFE) {
                $board['safe'][] = $row;
            } else {
                $waiting[] = ['row' => $row, 'group' => self::REASON_ORDER[$group], 'priority' => $contact->priority];
            }
        }

        usort($waiting, static fn(array $a, array $b): int => [$a['group'], -$a['priority']] <=> [$b['group'], -$b['priority']]);
        $board['noReply'] = array_column($waiting, 'row');

        return $board;
    }

    /**
     * The contact whose answer counts for this person, and the quake it belongs to when that is another one.
     *
     * @return array{Notification, ?string}
     */
    private function answering(Notification $contact): array
    {
        if ($contact->state !== Notification::MERGED || $contact->mergedIntoId === null) {
            return [$contact, null];
        }

        $target = $this->notifications->find($contact->mergedIntoId);

        return $target === null ? [$contact, null] : [$target, $this->earthquakes->find($target->earthquakeId)?->title];
    }

    /**
     * @return array{string, string} the reason shown, and its group in REASON_ORDER
     */
    private static function reason(Notification $contact, DateTimeImmutable $now, ?DateTimeImmutable $nextSlot): array
    {
        $sent = $contact->firstSentAt === null ? '' : self::time($contact->firstSentAt) . ' に送信';

        return match (true) {
            $contact->state === Notification::FAILED => ['届かなかった', 'failed'],
            $contact->autoRepliedAt !== null => ['不在の自動応答だけ', 'auto'],
            $contact->state === Notification::SENT => [self::sentReason($contact, $sent), 'sent'],
            $contact->state === Notification::PENDING && $contact->notBefore !== null && $contact->notBefore > $now => ['送信待ち（' . self::time($contact->notBefore) . ' に送る予定）', 'pending'],
            $contact->state === Notification::PENDING && $nextSlot !== null => ['送信待ち（上限のため ' . self::time($nextSlot) . ' 予定）', 'pending'],
            $contact->state === Notification::PENDING => ['送信待ち（次の cron で送ります）', 'pending'],
            $contact->state === Notification::HELD => ['保留（遅れて届いた地震）', 'other'],
            $contact->state === Notification::CANCELLED && $contact->sentCount > 0 => [$sent . '（取消済み）', 'other'],
            $contact->state === Notification::CANCELLED => ['取消のため送っていません', 'other'],
            default => ['', 'other'],
        };
    }

    private static function sentReason(Notification $contact, string $sent): string
    {
        $reason = $sent;

        if ($contact->sentCount > 1 && $contact->lastSentAt !== null) {
            $reason .= '、' . self::time($contact->lastSentAt) . ' に再送';
        }

        if ($contact->nextSendAt !== null) {
            $reason .= '（次の再送 ' . self::time($contact->nextSendAt) . '）';
        }

        return $reason;
    }

    /**
     * @return list<string>
     */
    private function bodies(Notification $contact): array
    {
        $bodies = [];

        foreach ($this->replies->forNotification($contact->id) as $reply) {
            if ($reply['kind'] === IncomingMail::REPLY && $reply['body'] !== '') {
                $bodies[] = $reply['body'];
            }
        }

        if ($contact->proxyNote !== null && $contact->proxyNote !== '') {
            $bodies[] = $contact->proxyNote;
        }

        return $bodies;
    }

    /**
     * @param list<MatchedCity> $cities
     */
    private static function places(array $cities): string
    {
        return implode('、', array_map(static function (MatchedCity $city): string {
            $label = SeismicIntensity::tryFrom($city->intensity ?? '')?->label();

            if ($city->basis === MatchedCity::BASIS_NONE) {
                return $city->place;
            }

            if ($city->notYetReceived) {
                return $city->place . ' ' . ($label ?? SeismicIntensity::FiveLower->label()) . '以上の可能性';
            }

            return $city->place . ($label === null ? '' : ' ' . $label);
        }, $cities));
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('G:i');
    }
}
