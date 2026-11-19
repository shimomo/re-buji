<?php

declare(strict_types=1);

// When only public/ is copied into the document root, public/rebuji-root.php returns where the rest of the app lives.
$root = is_file(__DIR__ . '/rebuji-root.php') ? (string) require __DIR__ . '/rebuji-root.php' : dirname(__DIR__);

// Before the autoloader, so that an older PHP gets this in Japanese, not a parse error or Composer's English.
$tooOld = (require $root . '/src/php-version.php')(PHP_VERSION_ID, PHP_VERSION);

if ($tooOld !== null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $tooOld, "\n";
    exit;
}

require $root . '/vendor/autoload.php';

$container = ReBuji\Container::build(new ReBuji\Paths($root), ['basePath' => ReBuji\Http\BasePath::detect($_SERVER)]);
ReBuji\Http\AppFactory::create($container)->run();
