<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

try {
    $contact = $nexiomConnect->contacts->create([
        'email' => 'ada@example.com',
        'firstName' => 'Ada',
        'lastName' => 'Lovelace',
    ]);

    echo $contact['id'], PHP_EOL;
} catch (NexiomException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
