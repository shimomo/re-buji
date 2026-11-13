<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use Slim\Exception\HttpNotFoundException;

/**
 * What an admin does about one person's contact on the manager screen.
 *
 * @author shimomo
 */
final class ContactsController
{
    public const int NOTE_LENGTH = 2000;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EarthquakeRepository $earthquakes,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    /**
     * "確認済みにする": an admin has read what the person wrote (design 5.6).
     *
     * @param array<string, string> $args
     */
    public function check(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $contact = $this->notifications->find((int) ($args['id'] ?? '0'));

        if ($contact === null || $contact->answer !== Notification::NEEDS_CHECK) {
            throw new HttpNotFoundException($request);
        }

        $this->notifications->markChecked($contact->id, self::adminName($request), $this->clock->now());

        return $this->backTo($contact);
    }

    /**
     * The form to record someone's state on their behalf, after a phone call for example (design 5.4).
     *
     * @param array<string, string> $args
     */
    public function proxyForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderProxy($request, $this->contact($request, $args), [], ['answer' => '', 'note' => '']);
    }

    /**
     * @param array<string, string> $args
     */
    public function proxy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $contact = $this->contact($request, $args);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $answer = self::text($body['answer'] ?? null);
        $note = trim(str_replace("\r\n", "\n", self::text($body['note'] ?? null)));
        $errors = [];

        if (!in_array($answer, [Notification::SAFE, Notification::NEEDS_CHECK], true)) {
            $errors[] = '無事か要確認かを選んでください。';
        }

        if (mb_strlen($note) > self::NOTE_LENGTH) {
            $errors[] = 'メモは ' . self::NOTE_LENGTH . ' 文字までです。';
        }

        if ($errors !== []) {
            return $this->renderProxy($request, $contact, $errors, ['answer' => $answer, 'note' => $note]);
        }

        $this->notifications->recordAnswer($this->answering($contact)->id, $answer, 'proxy', self::adminName($request), $note === '' ? null : $note, $this->clock->now());
        $this->flash->put('代理の記録を残しました。');

        return $this->backTo($contact);
    }

    /**
     * @param array<string, string> $args
     */
    private function contact(ServerRequestInterface $request, array $args): Notification
    {
        return $this->notifications->find((int) ($args['id'] ?? '0')) ?? throw new HttpNotFoundException($request);
    }

    /**
     * @param list<string> $errors
     * @param array{answer: string, note: string} $values
     */
    private function renderProxy(ServerRequestInterface $request, Notification $contact, array $errors, array $values): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('contacts/proxy', [
            'title' => '代理で記録',
            'admin' => $request->getAttribute('admin'),
            'contact' => $contact,
            'record' => self::record($this->answering($contact)),
            'quake' => $this->earthquakes->find($contact->earthquakeId),
            'errors' => $errors,
            'values' => $values,
        ]));

        return $response;
    }

    /**
     * A folded person's answer belongs to the contact they were folded into, which is the one that went out.
     */
    private function answering(Notification $contact): Notification
    {
        return $contact->state === Notification::MERGED && $contact->mergedIntoId !== null ? ($this->notifications->find($contact->mergedIntoId) ?? $contact) : $contact;
    }

    /**
     * What is on record now, so the admin sees what their record replaces.
     */
    private static function record(Notification $contact): string
    {
        if ($contact->answer === null) {
            return '今の記録：まだ答えがありません';
        }

        $how = $contact->answerSource === 'proxy' ? ($contact->answeredBy ?? '管理者') . ' さんが代理で記録' : '本人のメール';

        if ($contact->answer === Notification::NEEDS_CHECK && $contact->checkedAt !== null) {
            $how .= '、確認済み';
        }

        return '今の記録：' . ($contact->answer === Notification::SAFE ? '無事' : '要確認') . '（' . $how . '）';
    }

    private function backTo(Notification $contact): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes/' . $contact->earthquakeId));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function adminName(ServerRequestInterface $request): string
    {
        /** @var array{id: int, name: string, email: string} $admin */
        $admin = $request->getAttribute('admin');

        return $admin['name'];
    }
}
