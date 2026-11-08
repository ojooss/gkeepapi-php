<?php

declare(strict_types=1);

namespace Gkeepapi\Tools;

use Gkeepapi\Node\TopLevelNode;
use Gkeepapi\Node\Label;
use Gkeepapi\Keep;
use Gkeepapi\Node\ColorValue;
use Gkeepapi\Node\ListItem;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\Note;

/**
 * Scenario runner for the manual end-to-end test against the real Google Keep service (tools/integration-test.sh).
 *
 * It only ever creates, changes and deletes objects carrying a random, unique prefix generated for this run.
 * Existing notes of the account are never modified. Output contains only counts and the generated test names.
 */
final class IntegrationTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $warned = 0;

    private readonly string $prefix;
    private readonly string $labelName;

    /**
     * @param \Closure(): Keep $newKeep Creates a fresh, unauthenticated client.
     * @param \Closure(string): void $out Receives each output line.
     */
    public function __construct(
        private readonly \Closure $newKeep,
        private readonly string $email,
        #[\SensitiveParameter] private readonly string $masterToken,
        private readonly string $deviceId,
        private readonly \Closure $out,
    ) {
        $suffix = bin2hex(random_bytes(4));
        $this->prefix = 'gkeepapi-php-it-' . $suffix;
        $this->labelName = 'gkphp-it-' . $suffix;
    }

    /** Run all scenarios; returns true if no check failed. */
    public function run(): bool
    {
        $this->line('Test prefix: ' . $this->prefix);

        $keep = null;
        $baseline = 0;
        $noteId = '';
        $listId = '';
        $label = null;

        try {
            $this->section('1. Login and initial sync', function () use (&$keep, &$baseline): void {
                $keep = $this->connect();
                $baseline = count($keep->find(trashed: null));
                $this->check($keep->getMasterToken() === $this->masterToken, 'master token is kept in the session');
                $this->line("   account has {$baseline} notes/lists before the test");
            });
            if (!$keep instanceof Keep) {
                return $this->finish();
            }
            $client = $keep;

            $this->section('2. Create a note', function () use ($client, &$noteId): void {
                $note = $client->createNote($this->prefix . ' note', 'first line');
                $note->setPinned(true);
                $note->setColor(ColorValue::Red);
                $client->sync();
                $noteId = $note->id;
                $this->check($note->serverId !== null, 'note received a server id');
                $this->check(!$note->isDirty(), 'note is clean after sync');
                $this->check(
                    $note->serverId !== null && $client->get($note->serverId) === $note,
                    'lookup by server id',
                );
            });

            $this->section('3. Create a list (checked, indented)', function () use ($client, &$listId): void {
                $items = [['alpha', false], ['beta', true], ['gamma', false]];
                $list = $client->createList($this->prefix . ' list', $items);
                $items = $list->getItems();
                $items[0]->indent($items[2]);
                $client->sync();
                $listId = $list->id;
                $this->check($list->serverId !== null, 'list received a server id');
                $this->check($list->getText() === $this->expectedListText(), 'local list text and order');
            });

            $this->section('4. A fresh client sees the data', function () use ($noteId, $listId): void {
                $fresh = $this->connect();
                $this->assertNote($fresh, $noteId, 'first line', pinned: true, color: ColorValue::Red);
                $this->assertList($fresh, $listId);
            });

            $this->section('5. Update text and archive', function () use ($client, $noteId): void {
                $note = $client->get($noteId);
                $this->check($note instanceof Note, 'note still known locally');
                if (!$note instanceof Note) {
                    return;
                }
                $note->setText('second line');
                $note->setArchived(true);
                $client->sync();
                $fresh = $this->connect();
                $this->assertNote($fresh, $noteId, 'second line', pinned: true, color: ColorValue::Red, archived: true);
                $found = $fresh->find($this->prefix, archived: true);
                $this->check(count($found) === 1 && $found[0]->id === $noteId, 'find(archived: true) returns the note');
            });

            $this->section('6. Label', function () use ($client, $noteId, $listId, &$label): void {
                $label = $client->createLabel($this->labelName);
                $note = $client->get($noteId);
                $list = $client->get($listId);
                if (!$note instanceof TopLevelNode || !$list instanceof TopLevelNode) {
                    $this->check(false, 'note and list are known locally');

                    return;
                }
                $note->labels->add($label);
                $list->labels->add($label);
                $client->sync();
                $this->check(!$label->isDirty(), 'label is clean after sync');

                $fresh = $this->connect();
                $freshLabel = $fresh->findLabel($this->labelName);
                $this->check($freshLabel instanceof Label, 'a fresh client sees the label');
                if ($freshLabel instanceof Label) {
                    $labelled = $fresh->find(labels: [$freshLabel], trashed: null);
                    $ids = array_map(static fn (TopLevelNode $n): string => $n->id, $labelled);
                    sort($ids);
                    $expected = [$noteId, $listId];
                    sort($expected);
                    $this->check($ids === $expected, 'find(labels: [label]) returns exactly note and list');
                }
            });

            $this->section('7. Trash and restore', function () use ($client, $noteId): void {
                $note = $client->get($noteId);
                if (!$note instanceof TopLevelNode) {
                    $this->check(false, 'note is known locally');

                    return;
                }
                $note->trash();
                $client->sync();
                $fresh = $this->connect();
                $freshNote = $fresh->get($noteId);
                $this->check(
                    $freshNote instanceof TopLevelNode && $freshNote->isTrashed(),
                    'a fresh client sees the note as trashed',
                );
                $this->check(count($fresh->find($this->prefix, trashed: true)) === 1, 'find(trashed: true)');

                $note->untrash();
                $client->sync();
                $fresh = $this->connect();
                $freshNote = $fresh->get($noteId);
                $this->check(
                    $freshNote instanceof TopLevelNode && !$freshNote->isTrashed(),
                    'a fresh client sees the note restored',
                );
            });

            $this->section('8. Dump and restore', function () use ($client, $baseline): void {
                $state = json_decode(
                    json_encode($client->dump(), JSON_THROW_ON_ERROR),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $this->check(is_array($state), 'state survives a JSON round trip');
                if (!is_array($state)) {
                    return;
                }
                $offline = ($this->newKeep)();
                $offline->restore($state);
                $this->check(count($offline->find(trashed: null)) === $baseline + 2, 'offline restore has all notes');

                $resumed = ($this->newKeep)();
                $resumed->authenticate($this->email, $this->masterToken, $state, true, $this->deviceId);
                $this->check(count($resumed->find(trashed: null)) === $baseline + 2, 'resume from state and sync');
            });

            $this->section('9. Full resync', function () use ($client, $baseline): void {
                $client->sync(true);
                $this->check(count($client->find(trashed: null)) === $baseline + 2, 'resync reloads all notes');
            });
        } finally {
            $this->section('Cleanup', fn () => $this->cleanup());
        }

        return $this->finish();
    }

    private function cleanup(): void
    {
        $keep = $this->connect();

        // Only objects carrying this run's unique prefix are touched.
        foreach ($keep->find($this->prefix, trashed: null) as $node) {
            $node->delete();
            $node->trash();
        }
        foreach ($keep->labels() as $label) {
            if ($label->getName() === $this->labelName) {
                $keep->deleteLabel($label->id);
            }
        }
        $keep->sync();

        $check = $this->connect();
        $left = count($check->find($this->prefix, trashed: null));
        $this->check($left === 0, 'no test notes remain');
        if ($check->findLabel($this->labelName) instanceof Label) {
            $this->warn('the test label still exists (remove it manually in the Keep app)');
        }
    }

    private function assertNote(
        Keep $keep,
        string $id,
        string $text,
        bool $pinned,
        ColorValue $color,
        bool $archived = false,
    ): void {
        $note = $keep->get($id);
        $this->check($note instanceof Note, 'fresh client has the note');
        if (!$note instanceof Note) {
            return;
        }
        $this->check($note->getTitle() === $this->prefix . ' note', 'title persisted');
        $this->check($note->getText() === $text, 'text persisted');
        $this->check($note->isPinned() === $pinned, 'pinned persisted');
        $this->check($note->getColor() === $color, 'color persisted');
        $this->check($note->isArchived() === $archived, 'archived state persisted');
    }

    private function assertList(Keep $keep, string $id): void
    {
        $list = $keep->get($id);
        $this->check($list instanceof ListNode, 'fresh client has the list');
        if (!$list instanceof ListNode) {
            return;
        }
        $this->check($list->getTitle() === $this->prefix . ' list', 'list title persisted');
        $this->check($list->getText() === $this->expectedListText(), 'list items, order and checked state');
        $indented = array_map(
            static fn (ListItem $i): bool => $i->isIndented(),
            $list->getItems(),
        );
        $this->check($indented === [false, true, false], 'indentation persisted');
    }

    private function expectedListText(): string
    {
        return "\u{2610} alpha\n  \u{2610} gamma\n\u{2611} beta";
    }

    private function connect(): Keep
    {
        $keep = ($this->newKeep)();
        $keep->authenticate($this->email, $this->masterToken, null, true, $this->deviceId);

        return $keep;
    }

    private function section(string $title, \Closure $body): void
    {
        $this->line($title);
        try {
            $body();
        } catch (\Throwable $e) {
            $this->failed++;
            $this->line(sprintf('   [FAIL] %s: %s', $e::class, $e->getMessage()));
        }
    }

    private function check(bool $ok, string $what): void
    {
        if ($ok) {
            $this->passed++;
            $this->line('   [PASS] ' . $what);
        } else {
            $this->failed++;
            $this->line('   [FAIL] ' . $what);
        }
    }

    private function warn(string $what): void
    {
        $this->warned++;
        $this->line('   [WARN] ' . $what);
    }

    private function finish(): bool
    {
        $this->line('');
        $this->line(sprintf('Result: %d passed, %d failed, %d warnings', $this->passed, $this->failed, $this->warned));

        return $this->failed === 0;
    }

    private function line(string $text): void
    {
        ($this->out)($text);
    }
}
