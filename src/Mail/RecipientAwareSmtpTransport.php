<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Override;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * An SMTP transport that tells a refused recipient apart from every other failure.
 *
 * A failed log-in (535) or a refused sender is a 5xx too, so the status code alone does not say the problem is this
 * recipient's. Only answers to RCPT TO are sorted: a 5xx means this address will not take mail, except a 5.7.x policy
 * answer (relaying denied, often an account problem) which, like a 4xx, means "not now".
 *
 * @author shimomo
 */
final class RecipientAwareSmtpTransport extends EsmtpTransport
{
    #[Override]
    public function executeCommand(string $command, array $codes): string
    {
        try {
            return parent::executeCommand($command, $codes);
        } catch (UnexpectedResponseException $exception) {
            $code = $exception->getCode();

            if (!str_starts_with($command, 'RCPT TO:') || $code < 400 || $code >= 600) {
                throw $exception;
            }

            if ($code < 500 || preg_match('/\b5\.7\.\d{1,3}\b/', $exception->getMessage()) === 1) {
                throw new RecipientDeferredResponse($exception->getMessage(), $code, $exception);
            }

            throw new RecipientRefusedResponse($exception->getMessage(), $code, $exception);

            throw $exception;
        }
    }
}
