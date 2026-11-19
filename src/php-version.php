<?php

declare(strict_types=1);

// Old syntax on purpose: public/index.php and bin/cron.php read this before the autoloader, so it must run, and
// explain in Japanese, on the PHP versions it turns away (7.0 and later). Composer's own check would say it in English.

/**
 * @return string|null what to tell the person setting up, or null when this PHP is new enough
 */
return function (int $versionId, string $version) {
    if ($versionId < 80300) {
        return 'Re:無事 には PHP 8.3 以上が必要です（今は ' . $version . '）。サーバーの管理画面で PHP の版を変えてください。';
    }

    return null;
};
