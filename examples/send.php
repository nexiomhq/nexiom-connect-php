<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

try {
    $email = $nexiomConnect->emails->send([
        'from' => 'hello@your-verified-domain.com',
        'fromName' => 'Your team',
        'to' => 'customer@example.com',
        'subject' => 'Welcome',
        'html' => '<p>Thanks for joining us.</p>',
        'text' => 'Thanks for joining us.',
    ]);

    echo $email['messageId'], PHP_EOL;
} catch (NexiomException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
