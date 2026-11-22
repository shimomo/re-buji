<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Override;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Environment;
use ReBuji\Install\Steps\AdminStep;
use ReBuji\Paths;
use ReBuji\Text;

/**
 * Creates an admin from the server, so the first one can be made without a browser. The password is only ever typed
 * (or piped) at the prompt: never an argument, never printed.
 *
 * @author shimomo
 */
final class AdminCreateCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'admin:create';
    }

    #[Override]
    public function summary(): string
    {
        return '管理者を作る（引数：--name=名前 --email=アドレス。パスワードは聞かれる）';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        $options = [];

        foreach ($args as $arg) {
            if (preg_match('/\A--(name|email)=(.*)\z/su', $arg, $match) !== 1) {
                $io->error('使い方：console admin:create [--name=名前] [--email=アドレス]');

                return 2;
            }

            $options[$match[1]] = $match[2];
        }

        $name = Text::trim($options['name'] ?? $io->ask('名前：'));
        $email = trim($options['email'] ?? $io->ask('メールアドレス：'));
        $password = $io->secret('パスワード（12 文字以上）：');
        // A pipe gives one line only: there is nobody to type it twice.
        $confirmation = $io->interactive() ? $io->secret('もう一度：') : $password;
        $errors = AdminStep::validate($name, $email, $password, $confirmation);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $io->error($error);
            }

            return 1;
        }

        $admins = $this->container->get(AdminRepository::class);
        assert($admins instanceof AdminRepository);

        if ($admins->findByEmail($email) !== null) {
            $io->error('このメールアドレスの管理者はもういます。');

            return 1;
        }

        $admins->create($name, $email, $password);
        $io->line('管理者 ' . $name . '（' . $email . '）を作りました。');

        return 0;
    }
}
