<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;

/**
 * @author shimomo
 */
final class EnvironmentStep implements InstallStep
{
    #[Override]
    public function key(): string
    {
        return 'environment';
    }

    #[Override]
    public function title(): string
    {
        return '環境の確認';
    }

    #[Override]
    public function template(): string
    {
        return 'environment';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return ['problems' => $this->problems($context), 'https' => $context->isHttps(), 'phpVersion' => PHP_VERSION];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $problems = $this->problems($context);

        return $problems === [] ? StepResult::done() : StepResult::failed($problems);
    }

    /**
     * @return list<string>
     */
    private function problems(InstallContext $context): array
    {
        $problems = [];

        if (PHP_VERSION_ID < 80300) {
            $problems[] = 'PHP 8.3 以上が必要です（今は ' . PHP_VERSION . '）。サーバーの管理画面で PHP の版を変えてください。';
        }

        if (!extension_loaded('pdo')) {
            $problems[] = 'PHP の PDO 拡張がありません。';
        } elseif (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')) {
            $problems[] = 'PHP の pdo_sqlite か pdo_mysql の拡張が必要です。';
        }

        // symfony/http-client needs curl, or PHP's own streams with allow_url_fopen, to fetch the JMA feed.
        if (!extension_loaded('curl') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $problems[] = '気象庁の情報を取りに行けません。PHP の curl 拡張か、allow_url_fopen を有効にしてください。';
        }

        $storage = $context->paths->storage();

        if (!is_dir($storage) || !is_writable($storage)) {
            $problems[] = 'storage に書き込めません。FTP で storage/ の権限を 705 か 755 にしてください。';
        }

        return $problems;
    }
}
