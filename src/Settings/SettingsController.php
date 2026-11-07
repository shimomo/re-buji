<?php

declare(strict_types=1);

namespace ReBuji\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\RateLimit;
use ReBuji\Mail\RecipientRefused;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * The settings page (design 7): when to contact, resends, the hourly limit, the wording and the mail account.
 *
 * @author shimomo
 */
final class SettingsController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
        private readonly NotificationMail $mails,
        private readonly MailSender $sender,
        private readonly MessageRepository $messages,
        private readonly RateLimit $limit,
        private readonly ClockInterface $clock,
        private readonly Mailbox $mailbox,
        private readonly SystemState $state,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, $this->current(), []);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $values = SettingsForm::read(is_array($body) ? $body : []);
        $errors = SettingsForm::validate($values, false);

        if ($errors !== []) {
            return $this->render($request, ['smtp_password' => '', 'imap_password' => ''] + $values, $errors);
        }

        // Mail already in the mailbox is not read when the mailbox changes (see ReplyJob).
        if ($values['imap_host'] !== $this->settings->get('imap.host') || $values['imap_username'] !== $this->settings->get('imap.username')) {
            $this->state->set('imap.since', Time::format($this->clock->now()));
        }

        $this->settings->save(SettingsForm::toSettings($values));
        $this->flash->put('設定を保存しました。');

        return $this->redirect();
    }

    /**
     * Logs in to the reply mailbox to see that replies can be read.
     */
    public function testImap(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->mailbox->unseen($this->clock->now(), 1);
        } catch (MailboxUnavailable $exception) {
            return $this->render($request, $this->current(), ['メールを受信できませんでした：' . $exception->getMessage()]);
        }

        $this->flash->put('受信できました。');

        return $this->redirect();
    }

    /**
     * Sends a test mail to the admin who asked, counted in the hourly limit like any other mail.
     */
    public function testMail(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array{id: int, name: string, email: string} $admin */
        $admin = $request->getAttribute('admin');
        $now = $this->clock->now();

        if ($this->limit->remaining($now) <= 0) {
            return $this->render($request, $this->current(), ['1 時間の上限に達しているため、テストメールを送れません。']);
        }

        try {
            $mail = $this->mails->testMail($admin['email']);

            try {
                $this->sender->send($mail);
            } catch (RecipientRefused $exception) {
                $this->messages->record(null, 'test', $admin['email'], $mail->messageId, false, $exception->getMessage(), $now);

                throw $exception;
            }

            $this->messages->record(null, 'test', $admin['email'], $mail->messageId, true, '', $now);
        } catch (RecipientRefused|MailUnavailable $exception) {
            return $this->render($request, $this->current(), ['テストメールを送れませんでした：' . $exception->getMessage()]);
        }

        $this->flash->put('テストメールを ' . $admin['email'] . ' に送りました。届いたか確かめてください。');

        return $this->redirect();
    }

    /**
     * The saved settings by form field. The password is never shown.
     *
     * @return array<string, string>
     */
    private function current(): array
    {
        $values = [];

        foreach (SettingsForm::FIELDS as $field => $name) {
            $values[$field] = in_array($field, ['smtp_password', 'imap_password'], true) ? '' : $this->settings->get($name);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $errors
     */
    private function render(ServerRequestInterface $request, array $values, array $errors): ResponseInterface
    {
        $thresholds = array_map(static fn(string $value): array => ['value' => $value, 'label' => SeismicIntensity::from($value)->label()], SettingsForm::THRESHOLDS);
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('settings/index', [
            'title' => '設定',
            'admin' => $request->getAttribute('admin'),
            'values' => $values,
            'errors' => $errors,
            'thresholds' => $thresholds,
            'hasPassword' => $this->settings->get('smtp.password') !== '',
            'hasImapPassword' => $this->settings->get('imap.password') !== '',
            'placeholders' => NotificationMail::PLACEHOLDERS,
        ]));

        return $response;
    }

    private function redirect(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/settings'));
    }
}
