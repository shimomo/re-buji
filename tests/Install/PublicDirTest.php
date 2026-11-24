<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\ConfigFile;
use ReBuji\Install\PublicDir;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class PublicDirTest extends TestCase
{
    #[Test]
    public function placesThePublicFilesAndRemembersWhere(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            mkdir($dir . '/www');
            $public = $dir . '/www/anpi';
            $config = new ConfigFile($root . '/storage/config.php');
            $publicDir = new PublicDir(new Paths($root));

            $this->assertNull($publicDir->problem($public));
            $placed = $publicDir->place($public, $config);

            $this->assertSame(realpath($public), $placed);

            $this->assertSame('<?php // index', file_get_contents($public . '/index.php'));
            $this->assertSame('RewriteEngine On', file_get_contents($public . '/.htaccess'));
            $this->assertSame('body {}', file_get_contents($public . '/assets/app.css'));
            $this->assertSame('0644', substr(sprintf('%o', (int) fileperms($public . '/assets/app.css')), -4));
            /** @psalm-suppress UnresolvableInclude */
            $this->assertSame($root, require $public . '/rebuji-root.php');
            $this->assertSame($placed, $config->load()->get('upgrade.public_dir'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function addsToTheSettingsThatAreThere(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            $config = new ConfigFile($root . '/storage/config.php');
            file_put_contents($root . '/storage/config.php', "<?php\n\nreturn ['mail.from' => 'a@example.jp'];\n");

            (new PublicDir(new Paths($root)))->place($dir . '/www', $config);

            $this->assertSame('a@example.jp', $config->load()->get('mail.from'));
            $this->assertSame(realpath($dir . '/www'), $config->load()->get('upgrade.public_dir'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function acceptsAnEmptyDirectory(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            mkdir($dir . '/www');

            $this->assertNull((new PublicDir(new Paths($root)))->problem($dir . '/www'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesADirectoryWithOtherFiles(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $publicDir = new PublicDir(new Paths($this->copy($dir)));
            $public = $dir . '/www';
            mkdir($public . '/cgi-bin', 0755, true);
            touch($public . '/index.html');

            $this->assertSame(
                $public . ' にほかのファイルがあります（cgi-bin、index.html）。まだ無いか、空のディレクトリを渡してください。',
                $publicDir->problem($public),
            );

            $many = $dir . '/many';
            mkdir($many);

            foreach (range(1, 12) as $number) {
                touch($many . '/f' . sprintf('%02d', $number));
            }

            $this->assertSame(
                $many . ' にほかのファイルがあります（f01、f02、f03、f04、f05、f06、f07、f08、f09、f10、ほか 2 件）。まだ無いか、空のディレクトリを渡してください。',
                $publicDir->problem($many),
            );
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function placesAgainWhereItPlacedBefore(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            $public = $dir . '/www';
            $config = new ConfigFile($root . '/storage/config.php');
            $publicDir = new PublicDir(new Paths($root));
            $publicDir->place($public, $config);

            file_put_contents($public . '/index.php', 'changed');
            $this->assertNull($publicDir->problem($public));
            $publicDir->place($public, $config);
            $this->assertSame('<?php // index', file_get_contents($public . '/index.php'));

            unlink($public . '/index.php');
            unlink($public . '/.htaccess');
            $this->assertNull($publicDir->problem($public));
            $publicDir->place($public, $config);
            $this->assertSame('<?php // index', file_get_contents($public . '/index.php'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function placesAgainWhenOnlyThePointerWasWritten(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            $public = $dir . '/www';
            $config = new ConfigFile($root . '/storage/config.php');
            $publicDir = new PublicDir(new Paths($root));
            $publicDir->place($public, $config);

            foreach (['index.php', '.htaccess', 'assets/app.css'] as $name) {
                unlink($public . '/' . $name);
            }

            rmdir($public . '/assets');
            file_put_contents($public . '/extra.txt', 'x');

            $this->assertNull($publicDir->problem($public));
            $publicDir->place($public, $config);
            $this->assertSame('body {}', file_get_contents($public . '/assets/app.css'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAnotherCopysPublicDirectory(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $publicDir = new PublicDir(new Paths($this->copy($dir)));
            $other = $dir . '/other';
            $public = $dir . '/www';
            mkdir($public);
            file_put_contents($public . '/rebuji-root.php', "<?php\n\nreturn " . var_export($other, true) . ";\n");

            $this->assertSame($public . ' は、ほかの Re:無事（' . $other . '）の公開ディレクトリです。', $publicDir->problem($public));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAMissingParentANonDirectoryAndAnOverlap(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $root = $this->copy($dir);
            $publicDir = new PublicDir(new Paths($root));
            touch($dir . '/file');

            $this->assertSame($dir . '/no/such の親のディレクトリがありません。', $publicDir->problem($dir . '/no/such'));
            $this->assertSame($dir . '/file はディレクトリではありません。', $publicDir->problem($dir . '/file'));
            $this->assertSame($root . '/public と本体の場所（' . $root . '）が重なっています。', $publicDir->problem($root . '/public'));
            $this->assertSame($root . '/new と本体の場所（' . $root . '）が重なっています。', $publicDir->problem($root . '/new'));
            $this->assertSame($dir . ' と本体の場所（' . $root . '）が重なっています。', $publicDir->problem($dir));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * @return string the root of a copy made inside $dir
     */
    private function copy(string $dir): string
    {
        $root = $dir . '/app';
        mkdir($root . '/public/assets', 0755, true);
        mkdir($root . '/storage');
        file_put_contents($root . '/public/index.php', '<?php // index');
        file_put_contents($root . '/public/.htaccess', 'RewriteEngine On');
        file_put_contents($root . '/public/assets/app.css', 'body {}');
        chmod($root . '/public/assets/app.css', 0644);

        return $root;
    }
}
