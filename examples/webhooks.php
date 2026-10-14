<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomWebhookVerificationException;
use Nexiom\Connect\NexiomConnect;

// A plain PHP webhook endpoint. In Laravel, pass $request->getContent() and $request->headers->all().
$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

// The exact bytes received: decoded and re-encoded JSON no longer matches the signature.
$payload = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

try {
    $event = $nexiomConnect->webhooks->constructEvent(
        $payload,
        $headers,
        (string) getenv('NEXIOM_WEBHOOK_SECRET'),
    );
} catch (NexiomWebhookVerificationException $exception) {
    http_response_code(400);
    echo $exception->reason;
    exit;
}

switch ($event['type']) {
    case 'email.delivered':
        error_log('Delivered: ' . json_encode($event['data']));
        break;
    case 'whatsapp.message.failed':
        error_log('WhatsApp message failed: ' . json_encode($event['data']));
        break;
    default:
        error_log('Unhandled event type ' . $event['type']);
}

http_response_code(204);
