<?php

declare(strict_types=1);

namespace ReBuji;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Http\Url;
use ReBuji\Monitor\Health;

/**
 * The home page: whether cron is alive and the status URL for monitors.
 *
 * @author shimomo
 */
final class HomeController
{
    public function __construct(
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Health $health,
        private readonly ConfigFile $configFile,
        private readonly Url $url,
        private readonly EmployeeRepository $employees,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $lastCheck = $this->health->lastCheckedAt();
        $uri = $request->getUri();
        $port = $uri->getPort();
        $token = (string) $this->configFile->load()->get('status.token', '');

        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('home', [
            'title' => 'ホーム',
            'admin' => $request->getAttribute('admin'),
            'lastCheck' => $lastCheck?->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'),
            'stale' => $this->health->isStale(),
            'lastError' => $this->health->lastError(),
            'employeeCounts' => $this->employees->counts(),
            'unknownCities' => $this->employees->unknownCities(),
            'statusUrl' => $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port) . $this->url->to('/status/' . $token),
        ]));

        return $response;
    }
}
