<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Employees\EmployeeValidator;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use ReBuji\Time;
use Slim\Exception\HttpNotFoundException;

/**
 * /admins: the admins, adding one, and removing one. Nobody can remove themselves, so the one who is logged in always
 * remains and there is never no admin at all.
 *
 * @author shimomo
 */
final class AdminsController
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
    ) {
        //
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, [], ['name' => '', 'email' => '']);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $name = trim(self::text($body['name'] ?? null));
        $email = trim(self::text($body['email'] ?? null));
        $password = self::text($body['password'] ?? null);
        $errors = [];

        if ($name === '') {
            $errors[] = '名前を入れてください。';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '名前は 100 文字までです。';
        }

        if (!EmployeeValidator::isAddress($email)) {
            $errors[] = 'メールアドレスの形が正しくありません。';
        } elseif ($this->admins->findByEmail($email) !== null) {
            $errors[] = 'このメールアドレスの管理者はすでにいます。';
        }

        $errors = [...$errors, ...PasswordPolicy::validate($password)];

        if ($password !== self::text($body['password_confirmation'] ?? null)) {
            $errors[] = '確認のパスワードが一致しません。';
        }

        if ($errors !== []) {
            return $this->render($request, $errors, ['name' => $name, 'email' => $email]);
        }

        $this->admins->create($name, $email, $password);
        $this->flash->put($name . ' さんを管理者に加えました。');

        return $this->redirect('/admins');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? '0');

        if ($id === self::currentId($request)) {
            return $this->render($request, ['自分は削除できません。ほかの管理者に頼んでください。'], ['name' => '', 'email' => '']);
        }

        $admin = $this->admins->find($id);

        if ($admin === null) {
            throw new HttpNotFoundException($request);
        }

        $this->admins->delete($id);
        $this->flash->put($admin['name'] . ' さんを管理者から外しました。');

        return $this->redirect('/admins');
    }

    /**
     * The logged-in admin, which AuthMiddleware puts on the request.
     */
    private static function currentId(ServerRequestInterface $request): int
    {
        /** @var array{id: int, name: string, email: string} $admin */
        $admin = $request->getAttribute('admin');

        return $admin['id'];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param list<string> $errors
     * @param array{name: string, email: string} $values
     */
    private function render(ServerRequestInterface $request, array $errors, array $values): ResponseInterface
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $admins = array_map(static fn(array $admin): array => [
            'id' => $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'created' => Time::parse($admin['created_at'])->setTimezone($tokyo)->format('Y-m-d'),
        ], $this->admins->all());

        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('admins/index', [
            'title' => '管理者',
            'admin' => $request->getAttribute('admin'),
            'currentId' => self::currentId($request),
            'admins' => $admins,
            'errors' => $errors,
            'values' => $values,
        ]));

        return $response;
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to($path));
    }
}
