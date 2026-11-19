<?php

declare(strict_types=1);

// When only public/ is copied into the document root, public/rebuji-root.php returns where the rest of the app lives.
$root = is_file(__DIR__ . '/rebuji-root.php') ? (string) require __DIR__ . '/rebuji-root.php' : dirname(__DIR__);

// Before the autoloader, so that an older PHP gets this in Japanese rather than Composer's English. Up to here, and in
// the rest of this file, the syntax reads from PHP 5.5 on.
$check = require $root . '/src/php-version.php';
$tooOld = $check(PHP_VERSION_ID, PHP_VERSION, false);

if ($tooOld !== null) {
    header('Content-Type: text/plain; charset=UTF-8', true, 500);
    echo $tooOld, "\n";
    exit;
}

require $root . '/vendor/autoload.php';

$container = ReBuji\Container::build(new ReBuji\Paths($root), ['basePath' => ReBuji\Http\BasePath::detect($_SERVER)]);
ReBuji\Http\AppFactory::create($container)->run();
