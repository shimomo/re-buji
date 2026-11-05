<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use ReBuji\Install\ExposureCheck;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallKey;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;

/**
 * Refuses the key while storage/install-key.txt can be read from the web, since then anyone could have read it.
 *
 * @author shimomo
 */
final class KeyStep implements InstallStep
{
    public function __construct(private readonly InstallKey $key, private readonly ExposureCheck $check)
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
        if (!$this->key->matches($input['install_key'] ?? '')) {
            return StepResult::failed(['鍵が違います。FTP で storage/install-key.txt を開き、中の文字列をそのまま貼ってください。']);
        }

        // When the server cannot tell (no answer from its own URL), the exposure step asks again before setup ends.
        $publicUrl = $context->baseUrl();
        $result = $this->check->check($publicUrl, $context->packageUrl($publicUrl), ['storage/install-key.txt']);

        if ($result->exposed !== []) {
            $this->key->forget();

            return StepResult::failed([
                'storage/install-key.txt がウェブから読めるため、この鍵は使えません（' . $result->exposed[0] . '）。鍵は作り直しました。',
                '.htaccess が効くようにするか、本体を公開ディレクトリの外に置いて public/ の中身だけを公開する方法（README）に切り替えてから、新しい鍵を貼ってください。',
            ]);
        }

        return StepResult::done();
    }
}
