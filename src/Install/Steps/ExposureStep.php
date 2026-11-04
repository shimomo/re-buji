<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use ReBuji\Install\ExposureCheck;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;

/**
 * Refuses to finish setup while the database, the config file or the code can be read from the web.
 *
 * When the server cannot reach its own URL, the owner may check the listed URLs in a browser instead; an exposed file
 * can never be overridden this way.
 *
 * @author shimomo
 */
final class ExposureStep implements InstallStep
{
    public function __construct(private readonly ExposureCheck $check)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'exposure';
    }

    #[Override]
    public function title(): string
    {
        return '公開されていないことの確認';
    }

    #[Override]
    public function template(): string
    {
        return 'exposure';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        $draft = $context->draft();
        $baseUrl = isset($draft['base_url']) && is_string($draft['base_url']) ? $draft['base_url'] : $context->baseUrl();

        return ['baseUrl' => $baseUrl, 'paths' => ExposureCheck::PATHS];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $baseUrl = trim($input['base_url'] ?? '');
        $baseUrl = $baseUrl === '' ? $context->baseUrl() : $baseUrl;
        $context->putDraft('base_url', $baseUrl);
        $result = $this->check->check($baseUrl);

        if ($result->exposed !== []) {
            $errors = array_map(
                static fn(string $url): string => 'ウェブから見えています：' . $url . '（状態 ' . ($result->statuses[$url] ?? 0) . '）',
                $result->exposed,
            );
            $errors[] = 'このサーバーでは .htaccess が効いていない可能性があります。サーバーの設定を確かめるか、公開ディレクトリの外に本体を置き、public/ の中身だけを公開する方法（README）を使ってください。';

            return StepResult::failed($errors);
        }

        if ($result->unreachable !== [] && ($input['checked_by_hand'] ?? '') !== '1') {
            return StepResult::failed([
                'サーバーから自分の URL に接続できず、次の URL を確かめられませんでした：' . implode('、', $result->unreachable),
                'ブラウザでそれぞれの URL を開き、どれも表示されない（403 か 404 になる）ことを確かめてから、「ブラウザで確かめた」に印を付けて進んでください。',
            ]);
        }

        return StepResult::done();
    }
}
