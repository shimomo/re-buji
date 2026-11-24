<?php

declare(strict_types=1);

namespace ReBuji\Console;

/**
 * A line for a long wait, once a minute: an SSH session where nothing is printed for minutes can be dropped on the way.
 *
 * @author shimomo
 */
final class TimeLeft
{
    /**
     * The line to show after a sleep that took the seconds waited from $before to $after, out of $total: one each time a
     * whole minute is passed, none once the wait is over (what follows says so). Null when there is nothing to show.
     *
     * @param string $notYet what has not happened yet, such as 「まだ届いていません」
     */
    public static function line(string $notYet, int $before, int $after, int $total): ?string
    {
        if ($after >= $total || intdiv($after, 60) === intdiv($before, 60)) {
            return null;
        }

        return '  ' . $notYet . '（あと ' . (int) ceil(($total - $after) / 60) . ' 分待ちます）';
    }
}
