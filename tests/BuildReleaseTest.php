<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Version;
use ZipArchive;

/**
 * The release zip carries the version its code says it is: the version shows on the database update screen and in
 * the User-Agent sent to JMA.
 *
 * @author shimomo
 */
final class BuildReleaseTest extends TestCase
{
    #[Test]
    public function refusesAVersionTheCodeDoesNotCarry(): void
    {
        $output = [];
        exec(escapeshellarg(dirname(__DIR__) . '/bin/build-release.sh') . ' 9.9.9 2>&1', $output, $status);

        $this->assertSame(1, $status);
        // The script reads the committed version, which may differ from this working tree's.
        $this->assertMatchesRegularExpression('/^src\/Version\.php says \S+, not 9\.9\.9;/m', implode("\n", array_map(strval(...), $output)));
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/build/re-buji-9.9.9.zip');
    }

    #[Test]
    public function refusesATagThatIsNotHead(): void
    {
        // A copy of the script in a repository whose tag for this version is one commit behind.
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/bin');
            mkdir($root . '/src');
            copy(dirname(__DIR__) . '/bin/build-release.sh', $root . '/bin/build-release.sh');
            chmod($root . '/bin/build-release.sh', 0755);
            copy(dirname(__DIR__) . '/src/Version.php', $root . '/src/Version.php');
            $git = 'git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false -c tag.gpgsign=false';
            exec($git . ' init -q && ' . $git . ' add -A && ' . $git . ' commit -q -m one && ' . $git . ' tag v' . Version::CURRENT . ' && ' . $git . ' commit -q --allow-empty -m two 2>&1', $setup, $setupStatus);
            $this->assertSame(0, $setupStatus, implode("\n", array_map(strval(...), $setup)));

            $output = [];
            exec(escapeshellarg($root . '/bin/build-release.sh') . ' ' . Version::CURRENT . ' 2>&1', $output, $status);

            $this->assertSame(1, $status);
            $this->assertMatchesRegularExpression('/^tag v\S+ is at [0-9a-f]+, not HEAD;/m', implode("\n", array_map(strval(...), $output)));
            $this->assertDirectoryDoesNotExist($root . '/build');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsAboutFilesNotAdded(): void
    {
        // A class left out of git is left out of the zip, whose autoloader then cannot find it on the server.
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/bin');
            mkdir($root . '/src');
            copy(dirname(__DIR__) . '/bin/build-release.sh', $root . '/bin/build-release.sh');
            chmod($root . '/bin/build-release.sh', 0755);
            copy(dirname(__DIR__) . '/src/Version.php', $root . '/src/Version.php');
            $git = 'git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false';
            exec($git . ' init -q && ' . $git . ' add -A && ' . $git . ' commit -q -m one 2>&1', $setup, $setupStatus);
            $this->assertSame(0, $setupStatus, implode("\n", array_map(strval(...), $setup)));
            file_put_contents($root . '/src/Forgotten.php', "<?php\n");

            // composer then fails for want of a composer.json; only the warning matters here.
            $output = [];
            exec(escapeshellarg($root . '/bin/build-release.sh') . ' ' . Version::CURRENT . ' 2>&1', $output);

            $this->assertContains('warning: uncommitted or untracked files are not in the zip', array_map(strval(...), $output));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesTheDockerFilesOutOfTheZip(): void
    {
        // The zip is for rental servers; the Docker files are for people who clone the repository.
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/bin');
            mkdir($root . '/src');
            mkdir($root . '/build-files');
            mkdir($root . '/docker');
            copy(dirname(__DIR__) . '/bin/build-release.sh', $root . '/bin/build-release.sh');
            chmod($root . '/bin/build-release.sh', 0755);
            copy(dirname(__DIR__) . '/src/Version.php', $root . '/src/Version.php');
            copy(dirname(__DIR__) . '/build-files/zip.php', $root . '/build-files/zip.php');
            copy(dirname(__DIR__) . '/build-files/vendor.htaccess', $root . '/build-files/vendor.htaccess');
            file_put_contents($root . '/composer.json', '{"name": "test/test", "require": {}}');

            foreach (['docker/Dockerfile', 'compose.yaml', 'compose.proxy.yaml', 'compose.dev.yaml', '.env.example', '.dockerignore', 'README.md'] as $name) {
                file_put_contents($root . '/' . $name, "x\n");
            }

            $git = 'git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false';
            exec($git . ' init -q && ' . $git . ' add -A && ' . $git . ' commit -q -m one 2>&1', $setup, $setupStatus);
            $this->assertSame(0, $setupStatus, implode("\n", array_map(strval(...), $setup)));

            $output = [];
            exec(escapeshellarg($root . '/bin/build-release.sh') . ' ' . Version::CURRENT . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", array_map(strval(...), $output)));

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($root . '/build/re-buji-' . Version::CURRENT . '.zip') === true);
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }

            $zip->close();

            $this->assertContains('re-buji/README.md', $names);

            foreach (['docker/', 'compose.yaml', 'compose.proxy.yaml', 'compose.dev.yaml', '.env.example', '.dockerignore'] as $name) {
                $this->assertNotContains('re-buji/' . $name, $names);
            }

            $this->assertSame([], array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 're-buji/docker'))));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function shipsTheUserDocsButNotThePlans(): void
    {
        // The README in the zip links to docs/, so the user docs go with it; the plans and designs are for developers.
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/bin');
            mkdir($root . '/src');
            mkdir($root . '/build-files');
            mkdir($root . '/docs/superpowers/plans', 0777, true);
            mkdir($root . '/docs/superpowers/specs', 0777, true);
            copy(dirname(__DIR__) . '/bin/build-release.sh', $root . '/bin/build-release.sh');
            chmod($root . '/bin/build-release.sh', 0755);
            copy(dirname(__DIR__) . '/src/Version.php', $root . '/src/Version.php');
            copy(dirname(__DIR__) . '/build-files/zip.php', $root . '/build-files/zip.php');
            copy(dirname(__DIR__) . '/build-files/vendor.htaccess', $root . '/build-files/vendor.htaccess');
            file_put_contents($root . '/composer.json', '{"name": "test/test", "require": {}}');

            foreach (['docs/install.md', 'docs/superpowers/plans/plan.md', 'docs/superpowers/specs/spec.md'] as $name) {
                file_put_contents($root . '/' . $name, "x\n");
            }

            $git = 'git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false';
            exec($git . ' init -q && ' . $git . ' add -A && ' . $git . ' commit -q -m one 2>&1', $setup, $setupStatus);
            $this->assertSame(0, $setupStatus, implode("\n", array_map(strval(...), $setup)));

            $output = [];
            exec(escapeshellarg($root . '/bin/build-release.sh') . ' ' . Version::CURRENT . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", array_map(strval(...), $output)));

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($root . '/build/re-buji-' . Version::CURRENT . '.zip') === true);
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }

            $zip->close();

            $this->assertContains('re-buji/docs/install.md', $names);
            $this->assertSame([], array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 're-buji/docs/superpowers'))));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
