<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeZone;
use JmaXml\Earthquake\SeismicIntensity;

/**
 * The words that name a quake on screen: the heading and the line saying what it was judged on.
 *
 * @author shimomo
 */
final class QuakeHeading
{
    /**
     * "11月5日 3:12 ごろ　宮城県沖（M6.8）最大震度6弱", leaving out what is not known yet.
     */
    public static function heading(Earthquake $quake): string
    {
        $when = $quake->detectedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G:i');

        if ($quake->isDrill()) {
            return $when . '　訓練';
        }

        $where = ($quake->hypocenter ?? '') . ($quake->magnitude === null ? '' : '（M' . $quake->magnitude . '）');
        $strongest = SeismicIntensity::tryFrom($quake->maxIntensity ?? '')?->label();

        return trim($when . ' ごろ　' . $where . ($strongest === null ? '' : '最大' . $strongest));
    }

    public static function basis(Earthquake $quake): ?string
    {
        if ($quake->isDrill()) {
            return null;
        }

        $threshold = SeismicIntensity::tryFrom($quake->threshold ?? '')?->label() ?? '基準の震度';
        $source = $quake->basisTitle === null ? '' : $quake->basisTitle
            . ($quake->basisReportedAt === null ? '' : '（' . $quake->basisReportedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('G:i') . ' 発表）')
            . 'をもとに判定　｜　';

        return $source . '対象：' . $threshold . '以上の市町村に登録した人';
    }
}
