<?php

declare(strict_types=1);

namespace ReBuji\Install;

use League\Plates\Engine;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Http\Session;
use ReBuji\Http\Url;
use ReBuji\Paths;
use Slim\Exception\HttpNotFoundException;

/**
 * /install, /install/{step}: GET shows a step, POST submits it. A step opens only after every step before it.
 *
 * @author shimomo
 */
final class InstallController
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Session $session,
        private readonly Paths $paths,
        private readonly ConfigFile $configFile,
    ) {
        //
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->redirect('/install/' . $this->firstOpenStep($this->context($request))->key());
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = $this->context($request);
        $step = $this->step($request, $args['step'] ?? '');
        $open = $this->firstOpenStep($context);

        return $this->isReachable($step, $open) ? $this->render($step, $context, []) : $this->redirect('/install/' . $open->key());
    }

    /**
     * @param array<string, string> $args
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = $this->context($request);
        $step = $this->step($request, $args['step'] ?? '');
        $open = $this->firstOpenStep($context);

        if (!$this->isReachable($step, $open)) {
            return $this->redirect('/install/' . $open->key());
        }

        $result = $step->submit($context, $this->input($request));

        if (!$result->ok()) {
            return $this->render($step, $context, $result->errors);
        }

        $context->markCompleted($step->key());
        $next = $this->next($step);

        if ($next === null) {
            $context->forgetProgress();

            return $this->redirect('/login');
        }

        return $this->redirect('/install/' . $next->key());
    }

    /**
     * @param list<string> $errors
     */
    private function render(InstallStep $step, InstallContext $context, array $errors): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('install/' . $step->template(), [
            'title' => '初期設定：' . $step->title(),
            'step' => $step->key(),
            'steps' => array_map(static fn(InstallStep $s): array => ['key' => $s->key(), 'title' => $s->title()], $this->steps()),
            'errors' => $errors,
        ] + $step->viewData($context)));

        return $response;
    }

    private function isReachable(InstallStep $step, InstallStep $open): bool
    {
        $keys = array_map(static fn(InstallStep $s): string => $s->key(), $this->steps());

        return array_search($step->key(), $keys, true) <= array_search($open->key(), $keys, true);
    }

    private function firstOpenStep(InstallContext $context): InstallStep
    {
        $steps = $this->steps();

        foreach ($steps as $step) {
            if (!$context->completed($step->key())) {
                return $step;
            }
        }

        return $steps[count($steps) - 1];
    }

    private function next(InstallStep $current): ?InstallStep
    {
        $steps = $this->steps();

        foreach ($steps as $index => $step) {
            if ($step->key() === $current->key()) {
                return $steps[$index + 1] ?? null;
            }
        }

        return null;
    }

    private function step(ServerRequestInterface $request, string $key): InstallStep
    {
        foreach ($this->steps() as $step) {
            if ($step->key() === $key) {
                return $step;
            }
        }

        throw new HttpNotFoundException($request);
    }

    /**
     * @return non-empty-list<InstallStep>
     */
    private function steps(): array
    {
        $steps = InstallSteps::all($this->container);
        assert($steps !== []);

        return $steps;
    }

    /**
     * @return array<string, string>
     */
    private function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $input = [];

        if (is_array($body)) {
            foreach (array_keys($body) as $name) {
                if (is_string($name) && is_string($body[$name])) {
                    $input[$name] = $body[$name];
                }
            }
        }

        return $input;
    }

    private function context(ServerRequestInterface $request): InstallContext
    {
        return new InstallContext($this->paths, $this->configFile, $this->container, $request, $this->url, $this->session);
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to($path));
    }
}
