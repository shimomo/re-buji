<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallKey;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;

/**
 * @author shimomo
 */
final class KeyStep implements InstallStep
{
    public function __construct(private readonly InstallKey $key)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'key';
    }

    #[Override]
    public function title(): string
    {
        return '設置の鍵';
    }

    #[Override]
    public function template(): string
    {
        return 'key';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        $this->key->ensure();

        return [];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        return $this->key->matches($input['install_key'] ?? '')
            ? StepResult::done()
            : StepResult::failed(['鍵が違います。FTP で storage/install-key.txt を開き、中の文字列をそのまま貼ってください。']);
    }
}
