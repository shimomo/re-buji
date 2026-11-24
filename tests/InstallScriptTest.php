<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ZipArchive;

/**
 * bin/install.sh is what a user downloads alone and runs in an SSH session. Every case runs it with Linux's sh (dash) in
 * an empty environment and a throwaway HOME whose name holds a space; nothing here reaches GitHub, and the zips are
 * built here. The copy's bin/console is a stub that prints the arguments it was handed.
 *
 * @author shimomo
 */
final class InstallScriptTest extends TestCase
{
    #[Test]
    public function explainsBothLayoutsWithoutAPlace(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            [$status, $output] = $this->install($home, []);

            $this->assertSame(2, $status, $output);
            $this->assertStringContainsString('置き方を選んで、--public か --root を渡してください。', $output);
            $this->assertStringContainsString('  sh install.sh --public ~/www/anpi', $output);
            $this->assertStringContainsString('  sh install.sh --root ~/www/re-buji', $output);
            $this->assertDirectoryDoesNotExist($home . '/re-buji');
        });
    }

    #[Test]
    public function refusesAnInstalledRoot(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            self::copyOfRelease($home . '/re-buji');
            file_put_contents($home . '/re-buji/storage/config.php', "<?php\n\nreturn array (\n  'installed_at' => '2026-11-24T00:00:00Z',\n);\n");

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString($home . '/re-buji は設置を終えた Re:無事 です。上げるときは sh ' . $home . '/re-buji/bin/upgrade.sh を流してください。', $output);
        });
    }

    #[Test]
    public function refusesARootWithOtherFiles(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($home . '/re-buji');
            file_put_contents($home . '/re-buji/notes.txt', 'x');

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString($home . '/re-buji は空ではありません（notes.txt）。', $output);
        });
    }

    #[Test]
    public function refusesAPublicDirectoryWithOtherFiles(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($home . '/www/anpi', 0777, true);
            file_put_contents($home . '/www/anpi/index.html', 'x');

            [$status, $output] = $this->install($home, ['--public', $home . '/www/anpi']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('にほかのファイルがあります（index.html）', $output);
            $this->assertDirectoryDoesNotExist($home . '/re-buji');
        });
    }

    #[Test]
    public function refusesOverlappingPlaces(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($home . '/www');

            [$status, $output] = $this->install($home, ['--root', $home . '/www/re-buji', '--public', $home . '/www/re-buji/public']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('重なっています', $output);
        });
    }

    #[Test]
    public function refusesAMissingParentAndAMissingConfig(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            [$status, $output] = $this->install($home, ['--root', $home . '/nowhere/re-buji']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString($home . '/nowhere/re-buji の親のディレクトリがありません。', $output);

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji', '--config', $tmp . '/missing.env']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('設定ファイルがありません：' . $tmp . '/missing.env', $output);
            $this->assertDirectoryDoesNotExist($home . '/re-buji');
        });
    }

    #[Test]
    public function refusesAPhpOlderThan83AndDocker(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            file_put_contents($tmp . '/old-php', "#!/bin/sh\nexit 1\n");
            chmod($tmp . '/old-php', 0755);

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji'], ['PHP' => $tmp . '/old-php']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('は 8.3 以上のコマンドラインの php ではありません。', $output);

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji'], ['REBUJI_ENV_CONFIG' => '1']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('Docker では使いません', $output);
        });
    }

    #[Test]
    public function saysWhyWhenNoPhpIsFound(): void
    {
        // The script also looks in the places the hosts' manuals name; a machine that has one of them cannot show this.
        foreach (['/usr/bin/php8.3', '/usr/bin/php8.4', '/usr/bin/php8.5', '/opt/alt/php83/usr/bin/php', '/opt/alt/php84/usr/bin/php', '/opt/alt/php85/usr/bin/php'] as $place) {
            if (is_executable($place)) {
                $this->markTestSkipped($place . ' exists on this machine');
            }
        }

        if (glob('/usr/local/php/*/bin/php') !== [] && glob('/usr/local/php/*/bin/php') !== false) {
            $this->markTestSkipped('/usr/local/php exists on this machine');
        }

        $this->inHome(function (string $tmp, string $home): void {
            mkdir($tmp . '/empty-bin');
            symlink('/bin/sh', $tmp . '/empty-bin/sh');

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji'], [], false, $tmp . '/empty-bin');

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('8.3 以上のコマンドラインの php が見つかりません。env PHP=/path/to/php sh install.sh', $output);
        });
    }

    #[Test]
    public function saysWhyWhenHomeIsUnset(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            $command = 'cd ' . escapeshellarg($tmp) . ' && env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' PHP=' . escapeshellarg(PHP_BINARY)
                . ' sh ' . escapeshellarg(dirname(__DIR__) . '/bin/install.sh') . ' --public ' . escapeshellarg($tmp . '/anpi') . ' 2>&1';
            exec($command, $lines, $status);
            $output = implode("\n", $lines);

            $this->assertSame(1, $status, $output);
            $this->assertStringNotContainsString('parameter not set', $output);
            $this->assertStringContainsString('HOME', $output);
        });
    }

    #[Test]
    public function unpacksAZipAndHandsOverToTheConsole(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($tmp . '/bin');
            symlink(PHP_BINARY, $tmp . '/bin/php');
            mkdir($home . '/www');
            file_put_contents($tmp . '/rebuji.env', "REBUJI_DB_DRIVER=sqlite\n");
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', true);

            [$status, $output] = $this->install($home, [
                '--zip', $tmp . '/re-buji-9.9.9.zip',
                '--public', $home . '/www/anpi',
                '--url', 'https://example.jp/anpi',
                '--config', $tmp . '/rebuji.env',
            ], [], false, $tmp . '/bin:/usr/bin:/bin');

            $this->assertSame(0, $status, $output);
            $this->assertStringContainsString('php：' . $tmp . '/bin/php（' . PHP_VERSION . '）を使います', $output);
            $this->assertStringContainsString('zip を使います：', $output);
            $this->assertStringContainsString('✓ ' . $home . '/re-buji に展開しました', $output);
            $this->assertStringContainsString(json_encode([
                'install',
                '--public=' . $home . '/www/anpi',
                '--url=https://example.jp/anpi',
                '--config=' . $tmp . '/rebuji.env',
                '--php=' . $tmp . '/bin/php',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $output);
            $this->assertFileExists($home . '/re-buji/src/Version.php');
            $this->assertSame([], self::leftovers($home));
        });
    }

    #[Test]
    public function acceptsAnEmptyRootAndAPublicDirectoryThatPointsAtIt(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($home . '/re-buji');
            mkdir($home . '/anpi');
            file_put_contents($home . '/anpi/rebuji-root.php', "<?php\n\nreturn " . var_export($home . '/re-buji', true) . ";\n");
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', true);

            [$status, $output] = $this->install($home, ['--zip', $tmp . '/re-buji-9.9.9.zip', '--public', $home . '/anpi']);

            $this->assertSame(0, $status, $output);
            $this->assertFileExists($home . '/re-buji/src/Version.php');
            $this->assertSame([], self::leftovers($home));
        });
    }

    #[Test]
    public function startsOverInThePublicDirectoryAfterTheCopyWasDeleted(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            $public = $home . '/www/anpi';
            mkdir($public . '/assets', 0777, true);
            file_put_contents($public . '/.htaccess', 'RewriteEngine On');
            file_put_contents($public . '/assets/app.css', 'body {}');
            file_put_contents($public . '/index.php', '<?php');
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', true);

            // A copy that is not there either, but is not this one, is still another copy's.
            file_put_contents($public . '/rebuji-root.php', "<?php\n\nreturn " . var_export($home . '/other', true) . ";\n");
            [$status, $output] = $this->install($home, ['--zip', $tmp . '/re-buji-9.9.9.zip', '--public', $public]);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString($public . ' にほかのファイルがあります', $output);
            $this->assertDirectoryDoesNotExist($home . '/re-buji');

            file_put_contents($public . '/rebuji-root.php', "<?php\n\nreturn " . var_export($home . '/re-buji', true) . ";\n");
            [$status, $output] = $this->install($home, ['--zip', $tmp . '/re-buji-9.9.9.zip', '--public', $public]);

            $this->assertSame(0, $status, $output);
            $this->assertStringContainsString(json_encode(
                ['install', '--public=' . $public, '--php=' . PHP_BINARY],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ), $output);
            $this->assertFileExists($home . '/re-buji/src/Version.php');
            $this->assertSame([], self::leftovers($home));
        });
    }

    #[Test]
    public function stopsAtAVersionWithoutTheInstallCommand(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($home . '/www');
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', false);

            [$status, $output] = $this->install($home, ['--zip', $tmp . '/re-buji-9.9.9.zip', '--public', $home . '/www/anpi']);

            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('Re:無事 9.9.9 は install.sh で入れられません。0.4.0 以降を選んでください。', $output);
            $this->assertDirectoryDoesNotExist($home . '/re-buji');
            $this->assertSame([], self::leftovers($home));
        });
    }

    #[Test]
    public function continuesACopyThatIsNotInstalledWithoutDownloading(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            self::copyOfRelease($home . '/re-buji');

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji']);

            $this->assertSame(0, $status, $output);
            $this->assertStringContainsString($home . '/re-buji の Re:無事 9.9.9 の設定を続けます。', $output);
            $this->assertStringContainsString('["install","--php=', $output);
            $this->assertStringNotContainsString('zip を取っています', $output);
        });
    }

    #[Test]
    public function continuesTheCopyInTheHomeDirectoryWithoutAPlace(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            self::copyOfRelease($home . '/re-buji');

            [$status, $output] = $this->install($home, []);

            $this->assertSame(0, $status, $output);
            $this->assertStringContainsString('設定を続けます', $output);
            $this->assertStringContainsString('["install","--php=', $output);
        });
    }

    #[Test]
    public function passesOnTheConsolesExitStatus(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            self::copyOfRelease($home . '/re-buji');
            file_put_contents($home . '/re-buji/bin/console', '<?php echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES), "\n"; exit(3);');

            [$status, $output] = $this->install($home, ['--root', $home . '/re-buji']);

            $this->assertSame(3, $status, $output);
            $this->assertStringContainsString('["install","--php=' . PHP_BINARY . '"]', $output);
        });
    }

    #[Test]
    public function picksAPhpThatPrintsStartupWarnings(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($tmp . '/bin');
            self::warningPhp($tmp . '/bin/php');
            mkdir($home . '/www');
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', true);

            [$status, $output] = $this->install(
                $home,
                ['--zip', $tmp . '/re-buji-9.9.9.zip', '--public', $home . '/www/anpi'],
                [],
                false,
                $tmp . '/bin:/usr/bin:/bin',
            );

            $this->assertSame(0, $status, $output);
            $this->assertStringNotContainsString('Illegal number', $output);
            $this->assertStringContainsString('php：' . $tmp . '/bin/php（' . PHP_VERSION . '）を使います', $output);
            $this->assertStringContainsString('"--php=' . $tmp . '/bin/php"]', $output);
        });
    }

    #[Test]
    public function readsTheReleaseWithAPhpThatPrintsStartupWarnings(): void
    {
        $this->inHome(function (string $tmp, string $home): void {
            mkdir($tmp . '/bin');
            self::warningPhp($tmp . '/bin/php');
            mkdir($home . '/www');
            self::zipOfRelease($tmp . '/re-buji-9.9.9.zip', true);
            self::fakeGitHub($tmp . '/bin/curl', $tmp . '/re-buji-9.9.9.zip');

            [$status, $output] = $this->install(
                $home,
                ['--public', $home . '/www/anpi'],
                ['PHP' => $tmp . '/bin/php'],
                false,
                $tmp . '/bin:/usr/bin:/bin',
            );

            $this->assertSame(0, $status, $output);
            $this->assertStringContainsString('Re:無事 9.9.9 を ' . $home . '/re-buji に入れます。', $output);
            $this->assertStringContainsString('✓ zip の sha256 を確かめました：' . (string) hash_file('sha256', $tmp . '/re-buji-9.9.9.zip'), $output);
            $this->assertStringContainsString('"--php=' . $tmp . '/bin/php"]', $output);
            $this->assertSame([], self::leftovers($home));
        });
    }

    #[Test]
    public function keepsTheZipCheckIdenticalToUpgradeShs(): void
    {
        $install = file_get_contents(dirname(__DIR__) . '/bin/install.sh');
        $upgrade = file_get_contents(dirname(__DIR__) . '/bin/upgrade.sh');
        $this->assertIsString($install);
        $this->assertIsString($upgrade);

        $pieces = [
            'the sha256 function' => '/^sha256\(\) \{\n.*?\n\}\n/ms',
            'the release lookup, the JSON parser and the version and URL checks' => '/^\s*curl -fsSL -H .*?releases\/download\/v\$latest\/re-buji-\$latest\.zip"\).*?\n\s*esac\n/ms',
            'the sha256 given by GitHub' => '/^\s*case "\$expected" in.*?\$\{#expected\}[^\n]*\n/ms',
            'the download and the comparison' => '/^\s*curl -fsSL --proto.*?\n\s*fi\n/ms',
        ];

        foreach ($pieces as $name => $pattern) {
            $this->assertSame(self::normalised($upgrade, $pattern), self::normalised($install, $pattern), $name . ' differs between bin/install.sh and bin/upgrade.sh');
            $this->assertNotSame('', self::normalised($install, $pattern), $name . ' was not found');
        }
    }

    /**
     * @param callable(string, string): void $test given the temporary directory (resolved) and a HOME that holds a space
     */
    private function inHome(callable $test): void
    {
        $tmp = TemporaryDirectory::create();

        try {
            $resolved = (string) realpath($tmp);
            $home = $resolved . '/my home';
            mkdir($home);
            $test($resolved, $home);
        } finally {
            TemporaryDirectory::remove($tmp);
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{int, string} the exit status and the output with stderr
     */
    private function install(string $home, array $arguments, array $env = [], bool $withPhp = true, ?string $path = null): array
    {
        $variables = ['HOME' => $home, 'PATH' => $path ?? (string) getenv('PATH')];

        if ($withPhp) {
            $variables['PHP'] = PHP_BINARY;
        }

        $assignments = '';

        foreach ($env + $variables as $name => $value) {
            $assignments .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $command = 'cd ' . escapeshellarg(dirname($home)) . ' && env -i' . $assignments . ' sh ' . escapeshellarg(dirname(__DIR__) . '/bin/install.sh');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    /**
     * @return array<string, string> the files of an unpacked release: path => content
     */
    private static function releaseFiles(bool $withInstallCommand): array
    {
        $files = [
            're-buji/bin/console' => '<?php echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";',
            're-buji/src/Version.php' => "<?php\n\nfinal class Version\n{\n    public const CURRENT = '9.9.9';\n}\n",
            're-buji/storage/.htaccess' => "Require all denied\n",
        ];

        if ($withInstallCommand) {
            $files['re-buji/src/Console/InstallCommand.php'] = "<?php\n";
        }

        return $files;
    }

    private static function zipOfRelease(string $path, bool $withInstallCommand): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (self::releaseFiles($withInstallCommand) as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    private static function copyOfRelease(string $directory): void
    {
        foreach (self::releaseFiles(true) as $name => $content) {
            $file = $directory . substr($name, strlen('re-buji'));
            is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
            file_put_contents($file, $content);
        }
    }

    /**
     * A php whose php.ini shows startup errors (a library that will not load, a deprecated directive): it prints a
     * warning on stdout before anything else, unless it is run with -d display_errors=stderr.
     */
    private static function warningPhp(string $path): void
    {
        file_put_contents($path, "#!/bin/sh\n"
            . "for argument in \"\$@\"; do\n"
            . '    [ "$argument" != display_errors=stderr ] || exec ' . escapeshellarg(PHP_BINARY) . " \"\$@\"\n"
            . "done\n"
            . "echo\n"
            . "echo \"Warning: PHP Startup: Unable to load dynamic library 'missing.so' in Unknown on line 0\"\n"
            . 'exec ' . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
        chmod($path, 0755);
    }

    /**
     * A curl that stands in for GitHub: the latest release's JSON for the API, and $zip for that release's download URL.
     */
    private static function fakeGitHub(string $path, string $zip): void
    {
        $release = dirname($path) . '/release.json';
        file_put_contents($release, json_encode([
            'tag_name' => 'v9.9.9',
            'assets' => [[
                'name' => 're-buji-9.9.9.zip',
                'browser_download_url' => 'https://github.com/shimomo/re-buji/releases/download/v9.9.9/re-buji-9.9.9.zip',
                'digest' => 'sha256:' . (string) hash_file('sha256', $zip),
            ]],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($path, "#!/bin/sh\n"
            . "out=\"\"\n"
            . "url=\"\"\n"
            . "while [ \$# -gt 0 ]; do\n"
            . "    case \"\$1\" in\n"
            . "        -o) out=\$2; shift 2 ;;\n"
            . "        -H|--proto|--proto-redir) shift 2 ;;\n"
            . "        -*) shift ;;\n"
            . "        *) url=\$1; shift ;;\n"
            . "    esac\n"
            . "done\n"
            . "case \"\$url\" in\n"
            . '    https://api.github.com/repos/shimomo/re-buji/releases/latest) cat ' . escapeshellarg($release) . " > \"\$out\" ;;\n"
            . '    https://github.com/shimomo/re-buji/releases/download/v9.9.9/re-buji-9.9.9.zip) cat ' . escapeshellarg($zip) . " > \"\$out\" ;;\n"
            . "    *) exit 22 ;;\n"
            . "esac\n");
        chmod($path, 0755);
    }

    /**
     * @return list<string> what the script left in HOME's directory that is a work directory
     */
    private static function leftovers(string $home): array
    {
        return array_values(array_filter(
            array_merge(self::names($home), self::names(dirname($home))),
            static fn(string $name): bool => str_starts_with($name, '.re-buji-install-'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function names(string $directory): array
    {
        $names = scandir($directory);

        return $names === false ? [] : $names;
    }

    /**
     * The text matching $pattern without comments, blank lines or indentation, and with the names install.sh gives
     * differently (zipurl, $archive) put back.
     *
     * @param non-empty-string $pattern
     */
    private static function normalised(string $script, string $pattern): string
    {
        if (preg_match($pattern, $script, $matches) !== 1) {
            return '';
        }

        $lines = [];

        foreach (explode("\n", $matches[0]) as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = str_replace('$archive', '$work/release.zip', str_replace('zipurl', 'url', $line));
            }
        }

        return implode("\n", $lines);
    }
}
