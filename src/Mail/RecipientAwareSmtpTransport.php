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
 * recipient's. Only a 5xx answer to RCPT TO is.
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

            if (str_starts_with($command, 'RCPT TO:') && $code >= 500 && $code < 600) {
                throw new RecipientRefusedResponse($exception->getMessage(), $code, $exception);
            }

            throw $exception;
        }
    }
}
