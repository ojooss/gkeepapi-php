<?php

/**
 * Manual, opt-in check against the real Google Keep service. NEVER run this in CI or automated tests.
 *
 * Use a throwaway Google account. Credentials come from environment variables only:
 *
 *   GKEEP_EMAIL          account e-mail address
 *   GKEEP_MASTER_TOKEN   master token (aas_et/...)
 *   GKEEP_DEVICE_ID      optional, a stable device id (recommended)
 *   GKEEP_LIVE_WRITE=1   optional, additionally creates one new note and deletes it again
 *
 * Output contains counts and status only - never e-mail addresses, tokens or note contents.
 * See docs/live-check.md.
 */

declare(strict_types=1);

use Gkeepapi\Node\TopLevelNode;
use Gkeepapi\Keep;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\Note;
use Gkeepapi\Http\CurlHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/../vendor/autoload.php';

$email = getenv('GKEEP_EMAIL');
$masterToken = getenv('GKEEP_MASTER_TOKEN');
$deviceId = getenv('GKEEP_DEVICE_ID') ?: null;
$write = getenv('GKEEP_LIVE_WRITE') === '1';

if (!is_string($email) || $email === '' || !is_string($masterToken) || $masterToken === '') {
    fwrite(STDERR, "Set GKEEP_EMAIL and GKEEP_MASTER_TOKEN (throwaway account!). See docs/live-check.md.\n");
    exit(2);
}

$factory = new Psr17Factory();
$keep = new Keep(new CurlHttpClient($factory, $factory), $factory, $factory);

$step = static function (string $message): void {
    echo $message, "\n";
};

try {
    $step('1. Authenticate and sync (read-only) ...');
    $keep->authenticate($email, $masterToken, deviceId: $deviceId);

    $all = $keep->find(trashed: null);
    $notes = count(array_filter($all, static fn (TopLevelNode $n): bool => $n instanceof Note));
    $lists = count(array_filter($all, static fn (TopLevelNode $n): bool => $n instanceof ListNode));
    $trashed = count($keep->find(trashed: true));
    $labels = count($keep->labels());
    $step(sprintf('   OK: %d notes, %d lists (%d trashed), %d labels', $notes, $lists, $trashed, $labels));

    $step('2. Dump / restore round trip (offline) ...');
    $state = $keep->dump();
    $copy = new Keep(new CurlHttpClient($factory, $factory), $factory, $factory);
    $copy->restore($state);
    $step(count($copy->all()) === count($keep->all()) ? '   OK' : '   MISMATCH after restore');

    if (!$write) {
        $step('Write check skipped (set GKEEP_LIVE_WRITE=1 to create and delete one new note).');
        exit(0);
    }

    $step('3. Create one new note, sync, verify, delete it again ...');
    $note = $keep->createNote('gkeepapi-php live check', 'created by tools/live-check.php');
    $keep->sync();
    $serverId = $note->serverId;
    $synced = $serverId !== null && $keep->get($serverId) === $note;
    $step($synced ? '   OK: note has a server id' : '   FAILED: no server id');

    $note->delete();
    $note->trash();
    $keep->sync();
    $stillKnown = $keep->get($note->id) instanceof TopLevelNode;
    $step($stillKnown ? '   Note is still known locally (check the Keep app trash).' : '   OK: note removed');

    $step('4. Full resync ...');
    $keep->sync(true);
    $step(sprintf('   OK: %d top-level nodes after resync', count($keep->all())));
} catch (Throwable $e) {
    // Class and message only: auth errors carry Google's error code (e.g. BadAuthentication), not secrets.
    fwrite(STDERR, sprintf("FAILED: %s: %s\n", $e::class, $e->getMessage()));
    $previous = $e->getPrevious();
    if ($previous instanceof \Throwable) {
        fwrite(STDERR, sprintf("  caused by %s: %s\n", $previous::class, $previous->getMessage()));
    }
    exit(1);
}
