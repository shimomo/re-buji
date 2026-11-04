<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use Psr\Clock\ClockInterface;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallKey;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;
use ReBuji\Time;

/**
 * Shows what to set up outside the app (cron and the status URL) and then marks the copy as installed. The status
 * token is made when this page opens, because after setup the installer is gone.
 *
 * @author shimomo
 */
final class FinishStep implements InstallStep
{
    public function __construct(private readonly InstallKey $key, private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'finish';
    }

    #[Override]
    public function title(): string
    {
        return '仕上げ';
    }

    #[Override]
    public function template(): string
    {
        return 'finish';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return [
            'cronPath' => $context->paths->root() . '/bin/cron.php',
            'statusUrl' => $context->baseUrl() . '/status/' . $this->token($context),
        ];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $context->saveConfig(['status.token' => $this->token($context), 'installed_at' => Time::format($this->clock->now())]);
        $this->key->forget();

        return StepResult::done();
    }

    private function token(InstallContext $context): string
    {
        $draft = $context->draft();
        $token = isset($draft['status.token']) && is_string($draft['status.token']) ? $draft['status.token'] : null;

        if ($token === null) {
            $token = bin2hex(random_bytes(16));
            $context->putDraft('status.token', $token);
        }

        return $token;
    }
}
