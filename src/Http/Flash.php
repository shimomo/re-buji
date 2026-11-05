<?php

declare(strict_types=1);

namespace ReBuji\Http;

/**
 * A one-time message for the page after a redirect ("登録しました。"). The layout shows it through the flash()
 * template function.
 *
 * @author shimomo
 */
final class Flash
{
    private const string KEY = 'flash';

    public function __construct(private readonly Session $session)
    {
        //
    }

    public function put(string $message): void
    {
        $this->session->set(self::KEY, $message);
    }

    public function take(): ?string
    {
        $message = self::string($this->session->get(self::KEY));

        if ($message !== null) {
            $this->session->remove(self::KEY);
        }

        return $message;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
