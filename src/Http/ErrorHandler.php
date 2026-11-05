<?php

declare(strict_types=1);

namespace ReBuji\Http;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Generic error pages without details; unexpected errors go to the log.
 *
 * @author shimomo
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Engine $templates,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    public function __invoke(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;

        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception, 'path' => $request->getUri()->getPath()]);
        }

        [$title, $message] = match (true) {
            $exception instanceof HttpNotFoundException => ['ページが見つかりません', 'お探しのページはありません。'],
            $status >= 500 => ['エラーが起きました', '時間をおいて、もう一度お試しください。続くときは、管理者がログを確かめてください。'],
            default => ['ページを表示できません', 'もう一度お試しください。'],
        };

        $response = $this->responses->createResponse($status);
        $response->getBody()->write($this->templates->render('error', ['title' => $title, 'message' => $message]));

        return $response;
    }
}
