<?php

declare(strict_types=1);

namespace ReBuji\Quakes\Board;

/**
 * One person on the manager screen (design 5.6).
 *
 * @author shimomo
 */
final readonly class BoardRow
{
    /**
     * @param int $contactId the contact the admin's buttons act on (for a folded person, the one they were folded into)
     * @param list<string> $bodies what they wrote, oldest first, and an admin's note when recorded on their behalf
     * @param string|null $foldedInto the quake whose contact this person's answer comes from, when folded
     */
    public function __construct(
        public int $contactId,
        public string $name,
        public string $department,
        public string $places,
        public string $reason,
        public ?string $repliedAt,
        public array $bodies,
        public ?string $proxy,
        public ?string $foldedInto,
    ) {
        //
    }
}
