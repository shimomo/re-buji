<?php

declare(strict_types=1);

namespace ReBuji\Install;

use DI\FactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;

/**
 * The container bin/console install hands the installer's steps: the reply mailbox and the mail sender are built anew
 * each time they are asked for, because each keeps the mail settings it was built with, and a mail step asked again
 * must try the new answers. Everything else is the container's own.
 *
 * @author shimomo
 */
final readonly class RebuildingContainer implements ContainerInterface
{
    private const array REBUILT = [Mailbox::class, MailSender::class];

    public function __construct(private ContainerInterface&FactoryInterface $container)
    {
        //
    }

    #[Override]
    public function get(string $id): mixed
    {
        return in_array($id, self::REBUILT, true) ? $this->container->make($id) : $this->container->get($id);
    }

    #[Override]
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }
}
