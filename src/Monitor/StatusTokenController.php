<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Http\Url;

/**
 * POST /settings/status-token: replaces the status URL's token (the old URL stops working).
 *
 * @author shimomo
 */
final class StatusTokenController
{
    public function __construct(
        private readonly ConfigFile $configFile,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
    ) {
        //
    }

    public function regenerate(): ResponseInterface
    {
        $this->configFile->save(new Config(['status.token' => bin2hex(random_bytes(16))] + $this->configFile->load()->all()));

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/'));
    }
}
