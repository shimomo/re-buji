<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Install\Steps\AdminStep;
use ReBuji\Install\Steps\DatabaseStep;
use ReBuji\Install\Steps\EnvironmentStep;
use ReBuji\Install\Steps\FinishStep;
use ReBuji\Install\Steps\KeyStep;
use ReBuji\Paths;

/**
 * The installer's steps in order. Later plans insert theirs before "finish".
 *
 * @author shimomo
 */
final class InstallSteps
{
    /**
     * @return list<InstallStep>
     */
    public static function all(ContainerInterface $container): array
    {
        $paths = $container->get(Paths::class);
        $clock = $container->get(ClockInterface::class);
        assert($paths instanceof Paths && $clock instanceof ClockInterface);
        $key = new InstallKey($paths);

        return [
            new EnvironmentStep(),
            new KeyStep($key),
            new DatabaseStep($clock),
            new AdminStep($clock),
            new FinishStep($key, $clock),
        ];
    }
}
