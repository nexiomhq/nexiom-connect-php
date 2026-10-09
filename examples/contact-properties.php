<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nexiom\Connect\Exceptions\NexiomException;
use Nexiom\Connect\NexiomConnect;

$nexiomConnect = new NexiomConnect((string) getenv('NEXIOM_API_KEY'));

try {
    $property = $nexiomConnect->contacts->properties->create([
        'name' => 'company',
        'type' => 'string',
        'fallbackValue' => 'Unknown',
    ]);

    echo $property['id'], PHP_EOL;
} catch (NexiomException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
