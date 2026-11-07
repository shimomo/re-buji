<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Replies\ReplyText;

/**
 * Each case keeps only what the person wrote.
 *
 * @author shimomo
 */
final class ReplyTextTest extends TestCase
{
    #[Test]
    public function keepsAnEmptyReplyEmpty(): void
    {
        $this->assertSame('', ReplyText::extract("\n\n> 山田 花子 さん\n> 地震がありました\n"));
    }

    #[Test]
    public function dropsQuotesAfterTheWrittenLine(): void
    {
        $this->assertSame('無事です。', ReplyText::extract("無事です。\n\n2026年11月5日(木) 12:12 安否確認 <anpi@example.co.jp>:\n> 山田 花子 さん\n"));
    }

    #[Test]
    public function dropsTheIphoneQuote(): void
    {
        $this->assertSame('足を怪我しました', ReplyText::extract("足を怪我しました\n\n2026/11/05 12:12、安否確認 <anpi@example.co.jp>のメール:\n\n> 山田 花子 さん\n"));
    }

    #[Test]
    public function dropsTheOutlookHeaderBlock(): void
    {
        $this->assertSame('出社できません', ReplyText::extract("出社できません\r\n\r\n差出人: 安否確認 <anpi@example.co.jp>\r\n送信日時: 2026年11月5日 12:12\r\n件名: 地震がありました\r\n\r\n山田 花子 さん\r\n"));
    }

    #[Test]
    public function dropsTheJapaneseWroteLine(): void
    {
        $this->assertSame('家族も無事', ReplyText::extract("家族も無事\n\n安否確認 さんは書きました：\n山田 花子 さん\n地震がありました\n"));
    }

    #[Test]
    public function dropsTheEnglishWroteLine(): void
    {
        $this->assertSame('OK', ReplyText::extract("OK\n\nOn Thu, Nov 5, 2026 at 12:12 PM 安否確認 <anpi@example.co.jp> wrote:\n> 山田\n"));
    }

    #[Test]
    public function dropsTheOriginalMessageMarker(): void
    {
        $this->assertSame('大丈夫', ReplyText::extract("大丈夫\n\n-----Original Message-----\nFrom: anpi@example.co.jp\n"));
        $this->assertSame('大丈夫', ReplyText::extract("大丈夫\n\n-------- 元のメッセージ --------\n山田 花子 さん\n"));
    }

    #[Test]
    public function dropsTheSignature(): void
    {
        $this->assertSame('無事', ReplyText::extract("無事\n-- \n山田 花子\n静岡支店\n"));
    }

    #[Test]
    public function dropsOurOwnSubjectQuotedWithoutMarks(): void
    {
        $this->assertSame('大丈夫です', ReplyText::extract("大丈夫です\n件名: [安否確認 #a1b2c3d4] 地震がありました\n山田 花子 さん\n"));
    }

    #[Test]
    public function keepsFullWidthSpacesOnlyAsEmpty(): void
    {
        $this->assertSame('', ReplyText::extract("　\n　\n"));
    }

    #[Test]
    public function keepsSeveralLinesOfTheirOwn(): void
    {
        $this->assertSame("家は無事です。\n\n水道が止まっています。", ReplyText::extract("\n家は無事です。\n\n\n\n水道が止まっています。\n\n> 山田 花子 さん\n"));
    }
}
