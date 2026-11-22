<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Version;

/**
 * bin/release.sh refuses to tag or publish unless main is clean, pushed and carries the version, and the tag is new.
 * Each case runs the script in a throwaway clone with a local bare repository as origin; every refusal comes before
 * the build, so no composer or network is needed.
 *
 * @author shimomo
 */
final class ReleaseScriptTest extends TestCase
{
    #[Test]
    public function refusesABranchOtherThanMain(): void
    {
        $this->inClone(function (string $work): void {
            self::git($work, 'switch -q -c topic');

            $this->assertRefused($work, 'check ' . Version::CURRENT, '/^release from main, not topic$/m');
        });
    }

    #[Test]
    public function refusesAnUncleanTree(): void
    {
        $this->inClone(function (string $work): void {
            file_put_contents($work . '/src/Forgotten.php', "<?php\n");

            $this->assertRefused($work, 'check ' . Version::CURRENT, '/^the working tree is not clean; commit or remove these first:$/m');
        });
    }

    #[Test]
    public function refusesMainThatIsNotOnOrigin(): void
    {
        $this->inClone(function (string $work): void {
            self::git($work, 'commit -q --allow-empty -m two');

            $this->assertRefused($work, 'check ' . Version::CURRENT, '/^main is not the same as origin\/main; pull or push first$/m');
        });
    }

    #[Test]
    public function refusesAVersionTheCodeDoesNotCarry(): void
    {
        $this->inClone(function (string $work): void {
            $this->assertRefused($work, 'check 9.9.9', '/^src\/Version\.php says \S+, not 9\.9\.9; change it in a pull request first$/m');
        });
    }

    #[Test]
    public function refusesATagThatIsAlreadyOnOrigin(): void
    {
        $this->inClone(function (string $work): void {
            // Pushed from elsewhere: the local clone has not fetched it.
            self::git($work, 'tag v' . Version::CURRENT);
            self::git($work, 'push -q origin v' . Version::CURRENT);
            self::git($work, 'tag -d v' . Version::CURRENT);

            $this->assertRefused($work, 'check ' . Version::CURRENT, '/^tag v\S+ already exists; bump the version$/m');
        });
    }

    #[Test]
    public function refusesToPublishWithoutNotes(): void
    {
        $this->inClone(function (string $work): void {
            $this->assertRefused($work, 'publish ' . Version::CURRENT . ' missing.md', '/^release notes not found or empty: missing\.md$/m');
        });
    }

    #[Test]
    public function explainsHowToRunIt(): void
    {
        $this->inClone(function (string $work): void {
            $this->assertRefused($work, 'tag ' . Version::CURRENT, '/^usage: bin\/release\.sh check VERSION \| bin\/release\.sh publish VERSION NOTES_FILE$/m');
        });
    }

    /**
     * @param callable(string): void $test
     */
    private function inClone(callable $test): void
    {
        $root = TemporaryDirectory::create();

        try {
            exec('git init -q --bare ' . escapeshellarg($root . '/origin.git') . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", array_map(strval(...), $output)));
            $work = $root . '/work';
            mkdir($work . '/bin', 0777, true);
            mkdir($work . '/src');
            copy(dirname(__DIR__) . '/bin/release.sh', $work . '/bin/release.sh');
            chmod($work . '/bin/release.sh', 0755);
            copy(dirname(__DIR__) . '/src/Version.php', $work . '/src/Version.php');
            self::git($work, 'init -q -b main');
            self::git($work, 'add -A');
            self::git($work, 'commit -q -m one');
            self::git($work, 'remote add origin ' . escapeshellarg($root . '/origin.git'));
            self::git($work, 'push -q -u origin main');

            $test($work);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function assertRefused(string $work, string $arguments, string $pattern): void
    {
        $output = [];
        exec('cd ' . escapeshellarg($work) . ' && bin/release.sh ' . $arguments . ' 2>&1', $output, $status);
        $text = implode("\n", array_map(strval(...), $output));

        $this->assertSame(1, $status, $text);
        $this->assertMatchesRegularExpression($pattern, $text);
        $this->assertDirectoryDoesNotExist($work . '/build');
    }

    private static function git(string $work, string $command): void
    {
        exec('git -C ' . escapeshellarg($work) . ' -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false -c tag.gpgsign=false ' . $command . ' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new \RuntimeException('git ' . $command . ' failed: ' . implode("\n", array_map(strval(...), $output)));
        }
    }
}
