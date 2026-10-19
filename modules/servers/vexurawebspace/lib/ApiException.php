<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** A safe API error; response bodies and credentials never enter WHMCS logs. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $apiCode = '',
        public readonly string $requestId = '',
        public readonly int $retryAfter = 0,
        public readonly array $fields = [],
    ) {
        $reason = match ($status) {
            401 => 'API-Key fehlt, ist abgelaufen oder ungültig.',
            402 => 'Vexura-Guthaben reicht nicht aus oder Verlängerung ist gesperrt.',
            403 => 'API-Key oder Teamrechte erlauben diese Aktion nicht.',
            404 => 'Produkt oder Endpunkt ist nicht verfügbar.',
            409 => 'Preis, Ablaufdatum oder Bearbeitungsstatus hat sich geändert.',
            422 => 'API-Eingaben oder Produktstatus sind ungültig.',
            429 => 'API-Ratelimit erreicht. Die gespeicherte Anfrage später erneut prüfen.',
            default => 'API-Ergebnis nicht bestätigt. Gespeicherte Anfrage beibehalten.',
        };
        parent::__construct($reason.' HTTP '.$status.($requestId !== '' ? ' · Request-ID: '.$requestId : '').($fields !== [] ? ' · Felder: '.implode(', ', $fields) : ''));
    }
}
