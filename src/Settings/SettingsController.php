<?php

declare(strict_types=1);

namespace ReBuji\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Employees\EmployeeValidator;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\RateLimit;
use ReBuji\Mail\RecipientRefused;

/**
 * The settings page (design 7): when to contact, resends, the hourly limit, the wording and the mail account.
 *
 * @author shimomo
 */
final class SettingsController
{
    /**
     * Form fields and the settings they set. PHP turns dots in posted names into underscores, so the fields use
     * underscores.
     */
    public const array FIELDS = [
        'threshold' => 'threshold',
        'delay_minutes' => 'delay_minutes',
        'late_after_hours' => 'late_after_hours',
        'resend_count' => 'resend_count',
        'resend_interval_minutes' => 'resend_interval_minutes',
        'hourly_limit' => 'hourly_limit',
        'mail_subject' => 'mail.subject',
        'mail_body' => 'mail.body',
        'smtp_host' => 'smtp.host',
        'smtp_port' => 'smtp.port',
        'smtp_encryption' => 'smtp.encryption',
        'smtp_username' => 'smtp.username',
        'smtp_password' => 'smtp.password',
        'mail_from_address' => 'mail.from_address',
        'mail_from_name' => 'mail.from_name',
    ];

    /**
     * 震度 3 and below would send too often and use up the mail limit.
     */
    public const array THRESHOLDS = ['4', '5-', '5+', '6-', '6+', '7'];

    /**
     * Whole numbers on the form: the field, its range and the message when out of it.
     */
    private const array NUMBERS = [
        'delay_minutes' => [0, 60, '送るまでの待ち時間は 0〜60 分にしてください。'],
        'late_after_hours' => [1, 72, '遅れて届いた地震とみなすのは 1〜72 時間にしてください。'],
        'resend_count' => [0, 5, '再送の回数は 0〜5 回にしてください。'],
        'resend_interval_minutes' => [10, 1440, '再送の間隔は 10〜1440 分にしてください。'],
        'hourly_limit' => [10, 10000, '1 時間の上限は 10〜10000 通にしてください。'],
        'smtp_port' => [1, 65535, 'ポート番号は 1〜65535 にしてください。'],
    ];

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
        $body = is_array($body) ? $body : [];
        $values = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = self::text($body[$field] ?? null);
            $values[$field] = $field === 'mail_body' ? str_replace("\r\n", "\n", $value) : trim($value);
        }

        $errors = self::validate($values);

        if ($errors !== []) {
            return $this->render($request, ['smtp_password' => ''] + $values, $errors);
        }

        $saved = [];

        foreach (self::FIELDS as $field => $name) {
            $saved[$name] = $values[$field];
        }

        // An empty password field keeps the saved password, unless the whole account was cleared.
        if ($values['smtp_password'] === '' && ($values['smtp_host'] !== '' || $values['smtp_username'] !== '' || $values['mail_from_address'] !== '')) {
            unset($saved['smtp.password']);
        }

        $this->settings->save($saved);
        $this->flash->put('設定を保存しました。');

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
     * @param array<string, string> $values by form field
     *
     * @return list<string>
     */
    private static function validate(array $values): array
    {
        $errors = [];

        if (!in_array($values['threshold'], self::THRESHOLDS, true)) {
            $errors[] = 'しきい値を選んでください。';
        }

        foreach (self::NUMBERS as $field => [$min, $max, $message]) {
            $number = $values[$field];

            if (!ctype_digit($number) || (int) $number < $min || (int) $number > $max) {
                $errors[] = $message;
            }
        }

        $subjectLength = mb_strlen($values['mail_subject']);
        $bodyLength = mb_strlen(trim($values['mail_body']));

        if ($subjectLength < 1 || $subjectLength > 100) {
            $errors[] = '件名は 1〜100 文字にしてください。';
        }

        if ($bodyLength < 1 || mb_strlen($values['mail_body']) > 4000) {
            $errors[] = '本文は 1〜4000 文字にしてください。';
        }

        preg_match_all('/\{[^}]*\}/u', $values['mail_subject'] . $values['mail_body'], $found);
        $unknown = array_values(array_unique(array_diff($found[0], NotificationMail::PLACEHOLDERS)));

        if ($unknown !== []) {
            $errors[] = '文面に使えない差し込みがあります：' . implode('、', $unknown);
        }

        $account = $values['smtp_host'] !== '' || $values['smtp_username'] !== '' || $values['smtp_password'] !== '' || $values['mail_from_address'] !== '';

        if ($account && ($values['smtp_host'] === '' || $values['mail_from_address'] === '')) {
            $errors[] = 'SMTP のサーバー名と送信元のアドレスを入れてください。';
        }

        if (!in_array($values['smtp_encryption'], ['ssl', 'starttls'], true)) {
            $errors[] = '暗号化の方式を選んでください。';
        }

        if ($values['mail_from_address'] !== '' && !EmployeeValidator::isAddress($values['mail_from_address'])) {
            $errors[] = '送信元のアドレスの形が正しくありません。';
        }

        $nameLength = mb_strlen($values['mail_from_name']);

        if ($nameLength < 1 || $nameLength > 50) {
            $errors[] = '送信元の名前は 1〜50 文字にしてください。';
        }

        return $errors;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The saved settings by form field. The password is never shown.
     *
     * @return array<string, string>
     */
    private function current(): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $name) {
            $values[$field] = $field === 'smtp_password' ? '' : $this->settings->get($name);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $errors
     */
    private function render(ServerRequestInterface $request, array $values, array $errors): ResponseInterface
    {
        $thresholds = array_map(static fn(string $value): array => ['value' => $value, 'label' => SeismicIntensity::from($value)->label()], self::THRESHOLDS);
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('settings/index', [
            'title' => '設定',
            'admin' => $request->getAttribute('admin'),
            'values' => $values,
            'errors' => $errors,
            'thresholds' => $thresholds,
            'hasPassword' => $this->settings->get('smtp.password') !== '',
            'placeholders' => NotificationMail::PLACEHOLDERS,
        ]));

        return $response;
    }

    private function redirect(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/settings'));
    }
}
