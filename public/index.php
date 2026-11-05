<?php

declare(strict_types=1);

// When only public/ is copied into the document root, public/rebuji-root.php returns where the rest of the app lives.
$root = is_file(__DIR__ . '/rebuji-root.php') ? (string) require __DIR__ . '/rebuji-root.php' : dirname(__DIR__);

require $root . '/vendor/autoload.php';

$container = ReBuji\Container::build(new ReBuji\Paths($root), ['basePath' => ReBuji\Http\BasePath::detect($_SERVER)]);
ReBuji\Http\AppFactory::create($container)->run();
