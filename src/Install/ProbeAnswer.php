<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * What the public URL answered to the console's probe.
 *
 * @author shimomo
 */
final readonly class ProbeAnswer
{
    private function __construct(public ProbeOutcome $outcome, public string $detail, public ?WebSide $web)
    {
        //
    }

    public static function reached(WebSide $web): self
    {
        return new self(ProbeOutcome::Reached, '', $web);
    }

    /**
     * The connection itself failed.
     */
    public static function unreachable(string $why): self
    {
        return new self(ProbeOutcome::Unreachable, $why, null);
    }

    /**
     * An HTTP answer that is not from this Re:無事.
     */
    public static function refused(string $why): self
    {
        return new self(ProbeOutcome::Refused, $why, null);
    }

    public static function redirected(string $location): self
    {
        return new self(ProbeOutcome::Redirected, $location, null);
    }
}
