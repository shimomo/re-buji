<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use Psr\Clock\ClockInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Auth\PasswordPolicy;
use ReBuji\Database\Database;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;

/**
 * Creates the first admin. If one already exists (the step was submitted before), it moves on without another.
 *
 * @author shimomo
 */
final class AdminStep implements InstallStep
{
    public function __construct(private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'admin';
    }

    #[Override]
    public function title(): string
    {
        return '最初の管理者';
    }

    #[Override]
    public function template(): string
    {
        return 'admin';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return ['exists' => $this->admins($context)->count() > 0, 'values' => $context->draft()];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $admins = $this->admins($context);

        if ($admins->count() > 0) {
            return StepResult::done();
        }

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $context->putDraft('name', $name);
        $context->putDraft('email', $email);
        $errors = [];

        if ($name === '') {
            $errors[] = '名前を入れてください。';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'メールアドレスの形が正しくありません。';
        }

        $errors = [...$errors, ...PasswordPolicy::validate($password)];

        if ($password !== ($input['password_confirmation'] ?? '')) {
            $errors[] = '確認のパスワードが一致しません。';
        }

        if ($errors !== []) {
            return StepResult::failed($errors);
        }

        $admins->create($name, $email, $password);

        return StepResult::done();
    }

    private function admins(InstallContext $context): AdminRepository
    {
        return new AdminRepository(Database::fromConfig($context->config(), $context->paths), $this->clock);
    }
}
