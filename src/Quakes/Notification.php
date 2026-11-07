<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;

/**
 * One person's contact for one earthquake or drill (design 4: one per person and earthquake). The name and department
 * are copied, so the record stays when the employee is deleted.
 *
 * @author shimomo
 */
final readonly class Notification
{
    /**
     * Waiting to be sent once not_before has passed.
     */
    public const string PENDING = 'pending';

    /**
     * Of a quake that arrived late, waiting for an admin to decide.
     */
    public const string HELD = 'held';

    /**
     * Sent to at least one address; resent until a reply comes.
     */
    public const string SENT = 'sent';

    /**
     * Every address was refused.
     */
    public const string FAILED = 'failed';

    /**
     * Folded into an unanswered contact of a related quake moments before (merged_into_id).
     */
    public const string MERGED = 'merged';

    /**
     * The quake was cancelled. People already contacted get a cancel notice.
     */
    public const string CANCELLED = 'cancelled';

    /**
     * Not sent: the person was deleted or stopped, or an admin chose not to send a late quake.
     */
    public const string SKIPPED = 'skipped';

    /**
     * @param list<MatchedCity> $cities
     */
    public function __construct(
        public int $id,
        public int $earthquakeId,
        public ?int $employeeId,
        public string $employeeName,
        public string $employeeDepartment,
        public string $token,
        public array $cities,
        public int $priority,
        public string $state,
        public ?DateTimeImmutable $notBefore,
        public int $sentCount,
        public ?DateTimeImmutable $firstSentAt,
        public ?DateTimeImmutable $lastSentAt,
        public ?DateTimeImmutable $nextSendAt,
        public ?DateTimeImmutable $repliedAt,
        public ?string $cancelNotice,
        public ?int $mergedIntoId,
    ) {
        //
    }
}
