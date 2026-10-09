<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

try {
    $domain = $nexiomConnect->domains->create(['domain' => 'mail.example.com']);

    foreach ($domain['dns_records'] ?? [] as $record) {
        echo $record['record_type'], ' ', $record['name'], ' ', $record['value'], PHP_EOL;
    }
} catch (NexiomException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
