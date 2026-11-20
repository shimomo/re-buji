<?php

declare(strict_types=1);

// Run by the server's cron every 5 minutes (every minute where allowed). Never from the web: bin/ is denied by its
// .htaccess, and a request that still gets here is refused.

if (isset($_SERVER['REQUEST_METHOD'])) {
    if (!headers_sent()) {
        http_response_code(403);
    }

    echo "403 Forbidden\n";
    exit(1);
}

$root = dirname(__DIR__);

// Before the autoloader, so that an older PHP gets this in Japanese rather than Composer's English. This whole file
// reads from PHP 5.5 on.
$check = require $root . '/src/php-version.php';
$tooOld = $check(PHP_VERSION_ID, PHP_VERSION, true);

if ($tooOld !== null) {
    // Not STDERR: some hosts' cron "php" is a CGI build, where that constant does not exist.
    file_put_contents('php://stderr', $tooOld . "\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$paths = new ReBuji\Paths($root);
$configFile = new ReBuji\Config\ConfigFile($paths->storage('config.php'));

// Nothing to do until setup is finished.
if (!$configFile->exists() || $configFile->load()->get('installed_at') === null) {
    exit(0);
}

$container = ReBuji\Container::forCron($paths);
$state = $container->get(ReBuji\SystemState::class);
$clock = $container->get(Psr\Clock\ClockInterface::class);
$logger = $container->get(Psr\Log\LoggerInterface::class);
$migrator = $container->get(ReBuji\Database\Migrator::class);
assert($state instanceof ReBuji\SystemState && $clock instanceof Psr\Clock\ClockInterface && $logger instanceof Psr\Log\LoggerInterface);
assert($migrator instanceof ReBuji\Database\Migrator);

// A new version was uploaded but its database changes are not applied yet: the jobs would meet old tables. Nothing is
// recorded as run, so the status URL turns 503 and the external monitor tells someone.
if ($migrator->pending() !== []) {
    $state->set('cron.last_error', ReBuji\Monitor\Health::WAITING_FOR_UPDATE);
    $logger->warning('cron: waiting for the database update');
    exit(0);
}

(new ReBuji\Cron\CronRunner($paths->storage('cron.lock'), ReBuji\Cron\CronJobs::all($container), $state, $clock, $logger))->run();
