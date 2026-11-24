<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\SetupFile;
use ReBuji\Tests\Support\TemporaryDirectory;
use RuntimeException;

/**
 * @author shimomo
 */
final class SetupFileTest extends TestCase
{
    #[Test]
    public function readsEveryLineOfEnvExample(): void
    {
        $file = SetupFile::parse((string) file_get_contents(dirname(__DIR__, 2) . '/.env.example'));

        $this->assertSame(['driver' => 'sqlite'], $file->database());
        $this->assertSame([], $file->mail());
        $this->assertSame(['設定ファイルの REBUJI_VERSION は Docker だけで使う設定なので、使いません。'], $file->notices());
        $this->assertSame('設定ファイルから使います：REBUJI_DB_DRIVER=sqlite', $file->summary());
    }

    #[Test]
    public function treatsAnEmptyValueAsNotGiven(): void
    {
        $file = SetupFile::parse("REBUJI_SMTP_HOST=\nREBUJI_SMTP_PORT=465\nREBUJI_SMTP_PORT=587\n");

        $this->assertSame(['smtp_port' => '587'], $file->mail());
    }

    #[Test]
    public function readsLinesTheWayTheyAreWritten(): void
    {
        $file = SetupFile::parse(
            "export REBUJI_SMTP_HOST=smtp.example.jp # 送信\r\n"
            . "REBUJI_MAIL_FROM_NAME=\"安否 確認\"\r\n"
            . "REBUJI_IMAP_HOST='imap.example.jp'\n"
            . "REBUJI_SMTP_USERNAME=\${USER}\n",
        );

        $this->assertEqualsCanonicalizing(
            ['smtp_host' => 'smtp.example.jp', 'smtp_username' => '${USER}', 'mail_from_name' => '安否 確認', 'imap_host' => 'imap.example.jp'],
            $file->mail(),
        );
    }

    #[Test]
    public function readsAQuotedValueBeforeAComment(): void
    {
        $file = SetupFile::parse(
            "REBUJI_SMTP_HOST=\"smtp.example.jp\" # 送信\n"
            . "REBUJI_MAIL_FROM_NAME='安否 確認' # 名前\n"
            . "REBUJI_IMAP_HOST=\"imap # example\"\n"
            . "REBUJI_SMTP_PASSWORD=\"p w\"\t# 送信の\n"
            . "REBUJI_SMTP_USERNAME=\"anpi # 閉じていない\n",
        );

        $this->assertEqualsCanonicalizing(
            ['smtp_host' => 'smtp.example.jp', 'mail_from_name' => '安否 確認', 'imap_host' => 'imap # example', 'smtp_password' => 'p w', 'smtp_username' => '"anpi'],
            $file->mail(),
        );
    }

    #[Test]
    public function mapsTheDatabaseNames(): void
    {
        $file = SetupFile::parse(
            "REBUJI_DB_DRIVER=mysql\nREBUJI_DB_HOST=db.example.jp\nREBUJI_DB_PORT=3307\n"
            . "REBUJI_DB_NAME=anpi\nREBUJI_DB_USERNAME=anpi\nREBUJI_DB_PASSWORD=pw\n",
        );

        $this->assertEqualsCanonicalizing(
            ['driver' => 'mysql', 'mysql_host' => 'db.example.jp', 'mysql_port' => '3307', 'mysql_database' => 'anpi', 'mysql_username' => 'anpi', 'mysql_password' => 'pw'],
            $file->database(),
        );
    }

    #[Test]
    public function readsAPasswordFromAFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            file_put_contents($directory . '/secret', "s3cret\n");
            $file = SetupFile::parse('REBUJI_SMTP_PASSWORD_FILE=' . $directory . "/secret\n");

            $this->assertSame('s3cret', $file->mail()['smtp_password']);
            $this->assertFalse($file->holdsPassword());
            $this->assertStringContainsString($directory . '/secret', $file->summary());
            $this->assertStringNotContainsString('s3cret', $file->summary());

            try {
                SetupFile::parse('REBUJI_SMTP_PASSWORD_FILE=' . $directory . "/missing\n");
                $this->fail('A missing file must throw.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('REBUJI_SMTP_PASSWORD_FILE', $exception->getMessage());
            }
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function notesTheNamesItDoesNotUse(): void
    {
        $file = SetupFile::parse(
            "REBUJI_DOMAIN=anpi.example.jp\nREBUJI_MAIL_CA_FILE=/certs/ca.pem\nREBUJI_SMTP_HOTS=typo\nCOMPOSE_PROJECT_NAME=x\nnot a line\n",
        );

        $this->assertSame([
            '設定ファイルの REBUJI_DOMAIN は Docker だけで使う設定なので、使いません。',
            '設定ファイルの REBUJI_MAIL_CA_FILE は Docker だけで使う設定なので、使いません。',
            '設定ファイルの REBUJI_SMTP_HOTS は知らない名前なので、使いません。',
            '設定ファイルの 5 行目を読めません。',
        ], $file->notices());
    }

    #[Test]
    public function hidesPasswordsInTheSummary(): void
    {
        $file = SetupFile::parse("REBUJI_SMTP_HOST=smtp.example.jp\nREBUJI_SMTP_PASSWORD=pw\n");

        $this->assertSame('設定ファイルから使います：REBUJI_SMTP_HOST=smtp.example.jp、REBUJI_SMTP_PASSWORD=********', $file->summary());
        $this->assertTrue($file->holdsPassword());
    }

    #[Test]
    public function saysWhenOthersCanReadIt(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $path = $directory . '/setup.env';
            file_put_contents($path, "REBUJI_SMTP_PASSWORD=pw\n");

            chmod($path, 0644);
            $this->assertTrue(SetupFile::read($path)->readableByOthers());

            chmod($path, 0600);
            $this->assertFalse(SetupFile::read($path)->readableByOthers());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('設定ファイルを読めません：' . $directory . '/missing');
            SetupFile::read($directory . '/missing');
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }
}
