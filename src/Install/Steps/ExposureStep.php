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
 * The host is the one the installer was opened with. When the server cannot reach its own URL, or the URL does not
 * reach this copy, the owner may check the listed URLs in a browser instead; an exposed file can never be overridden
 * this way.
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
        $submitted = trim($input['base_url'] ?? '');
        $context->putDraft('base_url', $submitted === '' ? $context->baseUrl() : $submitted);
        $host = $context->request->getUri()->getHost();
        $publicUrl = self::sameHost($submitted === '' ? $context->baseUrl() : $submitted, $host);

        if ($publicUrl === null) {
            return StepResult::failed(['ホスト名は、いま開いている ' . $host . ' から変えられません。変えられるのは、http か https か、ポート番号、パスだけです。']);
        }

        $result = $this->check->check($publicUrl, $context->packageUrl($publicUrl));
        $byHand = ($input['checked_by_hand'] ?? '') === '1';

        if ($result->exposed !== []) {
            $errors = array_map(
                static fn(string $url): string => 'ウェブから見えています：' . $url . '（状態 ' . ($result->statuses[$url] ?? 0) . '）',
                $result->exposed,
            );
            $errors[] = 'このサーバーでは .htaccess が効いていない可能性があります。サーバーの設定を確かめるか、公開ディレクトリの外に本体を置き、public/ の中身だけを公開する方法（README）を使ってください。';

            return StepResult::failed($errors);
        }

        if ($result->probeFailure !== null && !$byHand) {
            return StepResult::failed([
                'サーバーから ' . $publicUrl . ' を開きましたが、この Re:無事 に届きませんでした（' . $result->probeFailure . '）。URL が正しいか確かめてください。',
                'URL が正しいのに届かない場合は、ブラウザで次の URL を開き、どれも表示されない（403 か 404 になる）ことを確かめてから、「ブラウザで確かめた」に印を付けて進んでください：' . implode('、', $result->unreachable),
            ]);
        }

        if ($result->unreachable !== [] && !$byHand) {
            return StepResult::failed([
                'サーバーから自分の URL に接続できず、次の URL を確かめられませんでした：' . implode('、', $result->unreachable),
                'ブラウザでそれぞれの URL を開き、どれも表示されない（403 か 404 になる）ことを確かめてから、「ブラウザで確かめた」に印を付けて進んでください。',
            ]);
        }

        return StepResult::done();
    }

    /**
     * The URL as scheme, host, port and path, or null when it is not http(s) on $host. Only the host is fixed: the
     * check must not be pointed at another server.
     */
    private static function sameHost(string $url, string $host): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (strcasecmp($parts['host'], $host) !== 0) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }
}
