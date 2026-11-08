<?php

/**
 * Full end-to-end test against the real Google Keep service. Started by tools/integration-test.sh, which asks for
 * the credentials. NEVER run this in CI or against an account with data you care about.
 *
 *   GKEEP_EMAIL, GKEEP_MASTER_TOKEN, GKEEP_DEVICE_ID   from the environment (set by the shell script)
 *
 * Only objects created by this run (random prefix) are modified or deleted.
 */

declare(strict_types=1);

use Gkeepapi\Http\CurlHttpClient;
use Gkeepapi\Keep;
use Gkeepapi\Tools\IntegrationTest;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/../vendor/autoload.php';

$email = getenv('GKEEP_EMAIL');
$masterToken = getenv('GKEEP_MASTER_TOKEN');
$deviceId = getenv('GKEEP_DEVICE_ID');

if (
    !is_string($email) || $email === '' || !is_string($masterToken) || $masterToken === ''
    || !is_string($deviceId) || $deviceId === ''
) {
    fwrite(STDERR, "Run tools/integration-test.sh (it asks for the credentials).\n");
    exit(2);
}

$factory = new Psr17Factory();
$test = new IntegrationTest(
    static fn (): Keep => new Keep(new CurlHttpClient($factory, $factory), $factory, $factory),
    $email,
    $masterToken,
    $deviceId,
    static function (string $line): void {
        echo $line, "\n";
    },
);

exit($test->run() ? 0 : 1);
