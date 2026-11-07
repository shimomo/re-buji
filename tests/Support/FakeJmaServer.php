<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the JMA site: the feeds and telegrams a test serves, 404 for anything else. Records requested URLs.
 *
 * @author shimomo
 */
final class FakeJmaServer implements ClientInterface
{
    /**
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @var array<string, string|int>
     */
    private array $answers = [];

    /**
     * A body is served with 200, a number as that status.
     */
    public function serve(string $url, string|int $answer): void
    {
        $this->answers[$url] = $answer;
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        $answer = $this->answers[$url] ?? 404;

        return is_int($answer) ? new Response($answer) : new Response(200, ['Content-Type' => 'application/xml'], $answer);
    }

    /**
     * An Atom feed in the shape JMA publishes (the same as jmaxml/feed's own fixture).
     *
     * @param list<array{url: string, title?: string}> $entries
     */
    public static function feed(string $updated, array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" lang="ja">'
            . '<title>fixture</title><subtitle>JMAXML publishing feed</subtitle>'
            . '<updated>' . $updated . '</updated>'
            . '<id>https://www.data.jma.go.jp/developer/xml/feed/fixture.xml</id>';

        foreach ($entries as $entry) {
            $url = htmlspecialchars($entry['url'], ENT_XML1);
            $xml .= '<entry>'
                . '<title>' . htmlspecialchars($entry['title'] ?? '震度速報', ENT_XML1) . '</title>'
                . '<id>' . $url . '</id>'
                . '<updated>' . $updated . '</updated>'
                . '<author><name>気象庁</name></author>'
                . '<link type="application/xml" href="' . $url . '"/>'
                . '<content type="text">fixture</content>'
                . '</entry>';
        }

        return $xml . '</feed>';
    }

    /**
     * A telegram URL jmaxml/feed can read the data type code from.
     */
    public static function telegramUrl(string $created, string $code): string
    {
        return 'https://www.data.jma.go.jp/developer/xml/data/' . $created . '_0_' . $code . '_010000.xml';
    }
}
