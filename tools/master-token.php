<?php

/**
 * Exchange the browser `oauth_token` cookie for a master token (manual helper for docs/live-check.md).
 *
 *   GKEEP_EMAIL         account e-mail address
 *   GKEEP_OAUTH_TOKEN   value of the `oauth_token` cookie (oauth2_4/...) from the browser login
 *   GKEEP_DEVICE_ID     optional, 16 hex characters; a random one is generated and printed otherwise
 *
 * Run it yourself in your own terminal: it prints the master token, which is a full-access secret.
 * Use it for a throwaway account only, and never paste the output anywhere.
 */

declare(strict_types=1);

use Gkeepapi\Auth\GpsOAuth;
use Gkeepapi\Http\CurlHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/../vendor/autoload.php';

$email = getenv('GKEEP_EMAIL');
$oauthToken = getenv('GKEEP_OAUTH_TOKEN');
$deviceId = getenv('GKEEP_DEVICE_ID') ?: bin2hex(random_bytes(8));

if (!is_string($email) || $email === '' || !is_string($oauthToken) || $oauthToken === '') {
    fwrite(STDERR, "Set GKEEP_EMAIL and GKEEP_OAUTH_TOKEN. See docs/live-check.md.\n");
    exit(2);
}

$factory = new Psr17Factory();
$gps = new GpsOAuth(new CurlHttpClient($factory, $factory), $factory, $factory);

try {
    $res = $gps->exchangeToken($email, $oauthToken, $deviceId);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("FAILED: %s: %s\n", $e::class, $e->getMessage()));
    exit(1);
}

if (!isset($res['Token'])) {
    fwrite(STDERR, 'FAILED: ' . ($res['Error'] ?? 'no token in response') . "\n");
    exit(1);
}

fwrite(STDERR, "Master token obtained. Keep GKEEP_DEVICE_ID the same in later runs.\n");
echo "GKEEP_DEVICE_ID={$deviceId}\n";
echo "GKEEP_MASTER_TOKEN={$res['Token']}\n";
