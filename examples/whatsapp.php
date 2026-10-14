<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

try {
    $message = $nexiomConnect->whatsapp->messages->send(
        [
            'sender' => '+213555000111',
            'to' => '+213555123456',
            'templateId' => 'wat_your_template_id',
            'variables' => [
                'body' => [1 => 'Amina', 2 => 'A-1234'],
            ],
        ],
        ['idempotencyKey' => 'order-A-1234-shipped'],
    );

    echo $message['id'], ' ', $message['status'], PHP_EOL;
} catch (NexiomException $error) {
    fwrite(STDERR, ($error->errorCode ?? 'error') . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
