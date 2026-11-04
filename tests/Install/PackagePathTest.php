<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\PackagePath;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class PackagePathTest extends TestCase
{
    #[Test]
    public function findsThePackageAboveTheScriptsPublicDirectory(): void
    {
        $docroot = TemporaryDirectory::create();

        try {
            mkdir($docroot . '/re-buji/public', 0700, true);
            touch($docroot . '/re-buji/public/index.php');
            // Opened as /re-buji/... (the root .htaccess forwards to public/) or as /re-buji/public/... directly.
            $server = ['SCRIPT_NAME' => '/re-buji/public/index.php', 'SCRIPT_FILENAME' => $docroot . '/re-buji/public/index.php'];

            $this->assertSame('/re-buji', PackagePath::detect($server, $docroot . '/re-buji'));
        } finally {
            TemporaryDirectory::remove($docroot);
        }
    }

    #[Test]
    public function findsAPackageThatIsTheDocumentRoot(): void
    {
        $docroot = TemporaryDirectory::create();

        try {
            mkdir($docroot . '/public');
            touch($docroot . '/public/index.php');
            $server = ['SCRIPT_NAME' => '/public/index.php', 'SCRIPT_FILENAME' => $docroot . '/public/index.php'];

            $this->assertSame('', PackagePath::detect($server, $docroot));
        } finally {
            TemporaryDirectory::remove($docroot);
        }
    }

    #[Test]
    public function findsThePackageInsideTheDocumentRootWhenOnlyPublicIsCopied(): void
    {
        $docroot = TemporaryDirectory::create();

        try {
            mkdir($docroot . '/app 1');
            touch($docroot . '/index.php');
            $server = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $docroot . '/index.php', 'DOCUMENT_ROOT' => $docroot . '/'];

            $this->assertSame('/app%201', PackagePath::detect($server, $docroot . '/app 1'));
        } finally {
            TemporaryDirectory::remove($docroot);
        }
    }

    #[Test]
    public function knowsNothingWhenThePackageIsOutsideTheDocumentRoot(): void
    {
        $base = TemporaryDirectory::create();

        try {
            mkdir($base . '/www');
            mkdir($base . '/re-buji');
            touch($base . '/www/index.php');
            $server = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $base . '/www/index.php', 'DOCUMENT_ROOT' => $base . '/www'];

            $this->assertNull(PackagePath::detect($server, $base . '/re-buji'));
            $this->assertNull(PackagePath::detect([], $base . '/re-buji'));
        } finally {
            TemporaryDirectory::remove($base);
        }
    }
}
