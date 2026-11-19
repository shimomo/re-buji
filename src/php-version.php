<?php

declare(strict_types=1);

// Old syntax on purpose: public/index.php and bin/cron.php read this before the autoloader, so it must run, and
// explain in Japanese, on the PHP versions it turns away. This file reads from PHP 5.3 on, the two entry points from
// 5.5 on (no parameter or return types, no arrow functions). Composer's own check would say it in English.

return
    /**
     * @param int $versionId PHP_VERSION_ID
     * @param string $version PHP_VERSION
     * @param bool $cron true for bin/cron.php, whose php is chosen in the cron command rather than the control panel;
     *                   optional, because v0.1.5's entry points pass two arguments (an upgrade meets them for a while)
     *
     * @return string|null what to tell the person setting up, or null when this PHP is new enough
     */
    function ($versionId, $version, $cron = false) {
        if ($versionId >= 80300) {
            return null;
        }

        $needed = 'Re:無事 には PHP 8.3 以上が必要です（今は ' . $version . '）。';

        if ($cron) {
            return $needed . 'cron に登録したコマンドで、PHP 8.3 以上の php を指定してください（場所はサーバーによって違います。さくらのレンタルサーバでは /usr/local/php/8.3/bin/php）。';
        }

        return $needed . 'サーバーの管理画面で PHP の版を変えてください。';
    };
