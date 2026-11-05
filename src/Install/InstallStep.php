<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * One step of the installer. Steps run in the order of InstallSteps::all(); a step opens only after every step before
 * it is done in the same session.
 *
 * @author shimomo
 */
interface InstallStep
{
    /**
     * The URL segment, such as "database" for /install/database.
     */
    public function key(): string;

    public function title(): string;

    /**
     * The template name under templates/install/.
     */
    public function template(): string;

    /**
     * @return array<string, mixed>
     */
    public function viewData(InstallContext $context): array;

    /**
     * @param array<string, string> $input
     */
    public function submit(InstallContext $context, array $input): StepResult;
}
