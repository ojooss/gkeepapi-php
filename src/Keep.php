<?php

declare(strict_types=1);

namespace Gkeepapi;

use Gkeepapi\Api\KeepAPI;
use Gkeepapi\Api\MediaAPI;
use Gkeepapi\Api\RemindersAPI;
use Gkeepapi\Auth\APIAuth;
use Gkeepapi\Auth\GpsOAuth;
use Gkeepapi\Exception\InvalidException;
use Gkeepapi\Exception\LabelException;
use Gkeepapi\Exception\LoginException;
use Gkeepapi\Exception\ParseException;
use Gkeepapi\Exception\ResyncRequiredException;
use Gkeepapi\Exception\SyncException;
use Gkeepapi\Exception\UpgradeRecommendedException;
use Gkeepapi\Node\Blob;
use Gkeepapi\Node\ColorValue;
use Gkeepapi\Node\Label;
use Gkeepapi\Node\ListItem;
use Gkeepapi\Node\ListNode;
use Gkeepapi\Node\Node;
use Gkeepapi\Node\NodeFactory;
use Gkeepapi\Node\Note;
use Gkeepapi\Node\Root;
use Gkeepapi\Node\TopLevelNode;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * High level Google Keep client.
 *
 * Manipulates a local copy of the Keep node tree. First, obtain a master token for your account.
 * To start, first authenticate:
 *
 *     $keep->authenticate($email, $masterToken);
 *
 * Individual notes can be retrieved by id:
 *
 *     $someNote = $keep->get('some_id');
 *
 * New notes can be created:
 *
 *     $newNote = $keep->createNote();
 *
 * These notes can then be modified:
 *
 *     $someNote->setText('Test');
 *     $newNote->setText('Text');
 *
 * These changes are automatically detected and synced up with:
 *
 *     $keep->sync();
 *
 * Port of gkeepapi/__init__.py `Keep` (MIT, (c) Kai Zhong / kiwiz).
 */
class Keep
{
    public const OAUTH_SCOPES = 'oauth2:https://www.googleapis.com/auth/memento'
        . ' https://www.googleapis.com/auth/reminders';

    private readonly KeepAPI $keepApi;
    private readonly RemindersAPI $remindersApi;
    private readonly MediaAPI $mediaApi;
    private readonly GpsOAuth $gpsOAuth;

    private ?string $keepVersion;

    /** @var array<string, Label> */
    private array $labels = [];

    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, string> Server ID => node ID. */
    private array $sidMap = [];

    /**
     * @param \Closure(int): void|null $sleeper Called with the seconds to wait when rate limited;
     *        defaults to `sleep()`.
     */
    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?\Closure $sleeper = null,
    ) {
        $this->keepApi = new KeepAPI($httpClient, $requestFactory, $streamFactory, null, $sleeper);
        $this->remindersApi = new RemindersAPI($httpClient, $requestFactory, $streamFactory, null, $sleeper);
        $this->mediaApi = new MediaAPI($httpClient, $requestFactory, $streamFactory, null, $sleeper);
        $this->gpsOAuth = new GpsOAuth($httpClient, $requestFactory, $streamFactory);
        $this->clear();
    }

    /**
     * Authenticate to Google with the provided credentials and sync. This flow is discouraged.
     *
     * @param string $email The account to use.
     * @param string $password The account password.
     * @param array<array-key, mixed>|null $state Serialized state to load.
     * @param bool $sync Whether to sync data.
     * @param string|null $deviceId Device id; defaults to an id derived from the host name.
     * @throws LoginException If there was a problem logging in.
     */
    public function login(
        string $email,
        #[\SensitiveParameter] string $password,
        ?array $state = null,
        bool $sync = true,
        ?string $deviceId = null,
    ): void {
        Log::logger()->warning("'Keep::login' is deprecated. Please use 'Keep::authenticate' instead");
        $auth = new APIAuth(self::OAUTH_SCOPES, $this->gpsOAuth);
        $auth->login($email, $password, $deviceId ?? $this->defaultDeviceId());
        $this->load($auth, $state, $sync);
    }

    /**
     * Renamed to {@see self::authenticate()}.
     *
     * @param array<array-key, mixed>|null $state
     * @throws LoginException If there was a problem logging in.
     */
    public function resume(
        string $email,
        #[\SensitiveParameter] string $masterToken,
        ?array $state = null,
        bool $sync = true,
        ?string $deviceId = null,
    ): void {
        Log::logger()->warning("'Keep::resume' has been renamed to 'Keep::authenticate'. Please update your code");
        $this->authenticate($email, $masterToken, $state, $sync, $deviceId);
    }

    /**
     * Authenticate to Google with the provided master token and sync.
     *
     * @param string $email The account to use.
     * @param string $masterToken The master token.
     * @param array<array-key, mixed>|null $state Serialized state to load.
     * @param bool $sync Whether to sync data.
     * @param string|null $deviceId Device id; defaults to an id derived from the host name.
     * @throws LoginException If there was a problem logging in.
     */
    public function authenticate(
        string $email,
        #[\SensitiveParameter] string $masterToken,
        ?array $state = null,
        bool $sync = true,
        ?string $deviceId = null,
    ): void {
        $auth = new APIAuth(self::OAUTH_SCOPES, $this->gpsOAuth);
        $auth->load($email, $masterToken, $deviceId ?? $this->defaultDeviceId());
        $this->load($auth, $state, $sync);
    }

    /** Get master token for resuming. */
    public function getMasterToken(): ?string
    {
        return $this->keepApi->getAuth()?->getMasterToken();
    }

    /**
     * Authenticate to Google with a prepared authentication object and sync.
     *
     * @param APIAuth $auth Authentication object.
     * @param array<array-key, mixed>|null $state Serialized state to load.
     * @param bool $sync Whether to sync data.
     * @throws LoginException If there was a problem logging in.
     */
    public function load(APIAuth $auth, ?array $state = null, bool $sync = true): void
    {
        $this->keepApi->setAuth($auth);
        $this->remindersApi->setAuth($auth);
        $this->mediaApi->setAuth($auth);

        if ($state !== null) {
            $this->restore($state);
        }
        if ($sync) {
            $this->sync();
        }
    }

    /**
     * Serialize note data.
     *
     * @return array{
     *     keep_version: string|null,
     *     labels: list<array<array-key, mixed>>,
     *     nodes: list<array<array-key, mixed>>
     * }
     */
    public function dump(): array
    {
        // Find all nodes manually, as the Keep object isn't aware of new ListItems until they've been synced
        // to the server.
        $nodes = [];
        foreach ($this->all() as $node) {
            $nodes[] = $node;
            foreach ($node->getChildren() as $child) {
                $nodes[] = $child;
            }
        }

        return [
            'keep_version' => $this->keepVersion,
            'labels' => array_map(
                static fn (Label $label): array => $label->save(false),
                $this->labels(),
            ),
            'nodes' => array_map(
                static fn (Node $node): array => $node->save(false),
                $nodes,
            ),
        ];
    }

    /**
     * Unserialize saved note data.
     *
     * @param array<array-key, mixed> $state Serialized state to load (as returned by {@see self::dump()}).
     * @throws InvalidException If the state is malformed.
     */
    public function restore(array $state): void
    {
        $labels = $state['labels'] ?? null;
        $nodes = $state['nodes'] ?? null;
        if (!array_key_exists('keep_version', $state) || !is_array($labels) || !is_array($nodes)) {
            throw new InvalidException('Invalid state');
        }
        $version = $state['keep_version'];
        if ($version !== null && !is_string($version)) {
            throw new InvalidException('Invalid state');
        }

        $this->clear();
        $this->parseUserInfo(['labels' => $labels]);
        $this->parseNodes($nodes);
        $this->keepVersion = $version;
    }

    /**
     * Get a note with the given ID (the local ID or the server ID).
     *
     * @return TopLevelNode|null The note or null if not found.
     */
    public function get(string $nodeId): ?TopLevelNode
    {
        $root = $this->nodes[Root::ID];
        $node = $root->get($nodeId);
        if ($node === null && array_key_exists($nodeId, $this->sidMap)) {
            $node = $root->get($this->sidMap[$nodeId]);
        }

        return $node instanceof TopLevelNode ? $node : null;
    }

    /**
     * Register a top level node (and its children) for syncing up to the server. There's no need to call this
     * for nodes created by {@see self::createNote()} or {@see self::createList()} as they are automatically added.
     *
     * @param Node $node The node to sync.
     * @throws InvalidException If the node is not a top level node.
     */
    public function add(Node $node): void
    {
        if ($node->parentId !== Root::ID) {
            throw new InvalidException('Not a top level node');
        }
        $this->nodes[$node->id] = $node;
        $this->nodes[Root::ID]->append($node, false);
    }

    /**
     * Find notes based on the specified criteria.
     *
     * @param Pattern|string|null $query A string or pattern to match against the title and text.
     * @param (callable(TopLevelNode): bool)|null $func A filter function.
     * @param list<Label|string>|null $labels Label ids or objects to match. An empty list matches notes with no labels.
     * @param list<ColorValue>|null $colors A list of colors to match.
     * @param bool|null $pinned Whether to match pinned notes.
     * @param bool|null $archived Whether to match archived notes.
     * @param bool|null $trashed Whether to match trashed notes (null matches both).
     * @return list<TopLevelNode> Search results.
     */
    public function find(
        Pattern|string|null $query = null,
        ?callable $func = null,
        ?array $labels = null,
        ?array $colors = null,
        ?bool $pinned = null,
        ?bool $archived = null,
        ?bool $trashed = false,
    ): array {
        $labelIds = $labels === null ? null : array_map(
            static fn (Label|string $label): string => $label instanceof Label ? $label->id : $label,
            $labels,
        );

        $results = [];
        foreach ($this->all() as $node) {
            if (
                $this->matchesQuery($node, $query)
                && ($func === null || $func($node))
                && $this->matchesLabels($node, $labelIds)
                && ($colors === null || in_array($node->getColor(), $colors, true))
                && ($pinned === null || $node->isPinned() === $pinned)
                && ($archived === null || $node->isArchived() === $archived)
                && ($trashed === null || $node->isTrashed() === $trashed)
            ) {
                $results[] = $node;
            }
        }

        return $results;
    }

    /**
     * Create a new managed note. Any changes to the note will be uploaded when {@see self::sync()} is called.
     *
     * @param string|null $title The title of the note.
     * @param string|null $text The text of the note.
     */
    public function createNote(?string $title = null, ?string $text = null): Note
    {
        $node = new Note();
        if ($title !== null) {
            $node->setTitle($title);
        }
        if ($text !== null) {
            $node->setText($text);
        }
        $this->add($node);

        return $node;
    }

    /**
     * Create a new list and populate it. Any changes to the note will be uploaded when {@see self::sync()} is called.
     *
     * @param string|null $title The title of the list.
     * @param list<array{0: string, 1: bool}>|null $items Each entry holds the text and checked status of a list item.
     */
    public function createList(?string $title = null, ?array $items = null): ListNode
    {
        $node = new ListNode();
        if ($title !== null) {
            $node->setTitle($title);
        }

        $sort = random_int(1000000000, 9999999999);
        foreach ($items ?? [] as [$text, $checked]) {
            $node->add($text, $checked, $sort);
            $sort -= ListNode::SORT_DELTA;
        }
        $this->add($node);

        return $node;
    }

    /**
     * Create a new label.
     *
     * @param string $name Label name.
     * @throws LabelException If the label exists.
     */
    public function createLabel(string $name): Label
    {
        if ($this->findLabel($name) instanceof Label) {
            throw new LabelException('Label exists');
        }
        $node = new Label();
        $node->setName($name);
        $this->labels[$node->id] = $node;

        return $node;
    }

    /**
     * Find a label with the given name.
     *
     * @param Pattern|string $query A string (case-insensitive, exact) or pattern to match against the name.
     * @param bool $create Whether to create the label if it doesn't exist (only if the query is a string).
     */
    public function findLabel(Pattern|string $query, bool $create = false): ?Label
    {
        foreach ($this->labels as $label) {
            // Match the label against query, which may be a string or Pattern.
            if (
                is_string($query)
                    ? mb_strtolower($query) === mb_strtolower($label->getName())
                    : $query->search($label->getName())
            ) {
                return $label;
            }
        }

        return $create && is_string($query) ? $this->createLabel($query) : null;
    }

    /** Get an existing label. */
    public function getLabel(string $labelId): ?Label
    {
        return $this->labels[$labelId] ?? null;
    }

    /** Deletes a label. */
    public function deleteLabel(string $labelId): void
    {
        if (!array_key_exists($labelId, $this->labels)) {
            return;
        }

        $label = $this->labels[$labelId];
        $label->delete();
        foreach ($this->all() as $node) {
            $node->labels->remove($label);
        }
    }

    /**
     * Get all labels.
     *
     * @return list<Label>
     */
    public function labels(): array
    {
        return array_values($this->labels);
    }

    /**
     * Get the canonical link to media. The HTTP client must not follow redirects.
     *
     * @param Blob $blob The media resource.
     * @return string A link to the media.
     */
    public function getMediaLink(Blob $blob): string
    {
        return $this->mediaApi->get($blob);
    }

    /**
     * Get all notes.
     *
     * @return list<TopLevelNode>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->nodes[Root::ID]->getChildren(),
            static fn (Node $node): bool => $node instanceof TopLevelNode,
        ));
    }

    /**
     * Sync the local Keep tree with the server. If resyncing, local changes will be destroyed. Otherwise, local
     * changes to notes and labels will be detected and synced up.
     *
     * Like upstream, reminders are not synced.
     *
     * @param bool $resync Whether to resync data.
     * @throws SyncException If there is a consistency issue.
     */
    public function sync(bool $resync = false): void
    {
        // Clear all state if we want to resync.
        if ($resync) {
            $this->clear();
        }

        $this->syncNotes();
    }

    private function clear(): void
    {
        $this->keepVersion = null;
        $this->labels = [];
        $this->nodes = [];
        $this->sidMap = [];

        $this->nodes[Root::ID] = new Root();
    }

    private function syncNotes(): void
    {
        // Fetch updates until we reach the newest version.
        while (true) {
            Log::logger()->debug('Starting keep sync');

            // Collect any changes and send them up to the server.
            $labelsUpdated = false;
            foreach ($this->labels as $label) {
                if ($label->isDirty()) {
                    $labelsUpdated = true;
                    break;
                }
            }

            $changes = $this->keepApi->changes(
                $this->keepVersion,
                array_map(static fn (Node $node): array => $node->save(), $this->findDirtyNodes()),
                $labelsUpdated
                    ? array_map(static fn (Label $label): array => $label->save(), array_values($this->labels))
                    : [],
            );

            if (!empty($changes['forceFullResync'])) {
                throw new ResyncRequiredException('Full resync required');
            }
            if (!empty($changes['upgradeRecommended'])) {
                throw new UpgradeRecommendedException('Upgrade recommended');
            }

            // Hydrate labels.
            if (array_key_exists('userInfo', $changes)) {
                $userInfo = $changes['userInfo'];
                if (!is_array($userInfo)) {
                    throw new ParseException('Invalid userInfo', $changes);
                }
                $this->parseUserInfo($userInfo);
            }

            // Hydrate notes and any children.
            if (array_key_exists('nodes', $changes)) {
                $nodes = $changes['nodes'];
                if (!is_array($nodes)) {
                    throw new ParseException('Invalid nodes', $changes);
                }
                $this->parseNodes($nodes);
            }

            $toVersion = $changes['toVersion'] ?? null;
            if (!is_string($toVersion)) {
                throw new SyncException('Missing toVersion');
            }
            $this->keepVersion = $toVersion;
            Log::logger()->debug('Finishing sync');

            // Check if there are more changes to retrieve.
            if (empty($changes['truncated'])) {
                break;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseNodes(array $raw): void
    {
        $createdNodes = [];
        $deletedNodes = [];
        $listitemNodes = [];

        // Loop over each updated node.
        foreach ($raw as $rawNode) {
            if (!is_array($rawNode) || !isset($rawNode['id']) || !is_string($rawNode['id'])) {
                throw new ParseException('Node without id', is_array($rawNode) ? $rawNode : []);
            }
            $id = $rawNode['id'];

            // If the id exists, then we already know about it. In other words, update a local node.
            if (array_key_exists($id, $this->nodes)) {
                $node = $this->nodes[$id];
                if (array_key_exists('parentId', $rawNode)) {
                    // If the parentId field is set, this is an update. Load it into the existing node.
                    $node->load($rawNode);
                    $this->rememberServerId($node);
                    Log::logger()->debug('Updated node', ['id' => $id]);
                } else {
                    // Otherwise, this node has been deleted. Add it to the list.
                    $deletedNodes[] = $node;
                }
            } else {
                // Otherwise, this is a new node. Attempt to hydrate it.
                $node = NodeFactory::fromJson($rawNode);
                if (!$node instanceof Node) {
                    Log::logger()->debug('Discarded unknown node');
                } else {
                    // Append the new node into the node tree.
                    $this->nodes[$id] = $node;
                    $this->rememberServerId($node);
                    $createdNodes[] = $node;
                    Log::logger()->debug('Created node', ['id' => $id]);
                }
            }

            // If the node is a listitem, keep track of it.
            if ($node instanceof ListItem) {
                $listitemNodes[] = $node;
            }
        }

        // Attach each listitem to its parent list. Indented items point to their parent listitem, so we need to
        // traverse up until we reach the list.
        foreach ($listitemNodes as $node) {
            $prev = $node->prevSuperListItemId;
            $curr = $node->superListItemId;
            if ($prev === $curr) {
                continue;
            }

            // Apply proper indentation.
            if ($prev !== null && ($this->nodes[$prev] ?? null) instanceof ListItem) {
                $this->nodes[$prev]->dedent($node, false);
            }
            if ($curr !== null && ($this->nodes[$curr] ?? null) instanceof ListItem) {
                $this->nodes[$curr]->indent($node, false);
            }
        }

        // Attach created nodes to the tree.
        foreach ($createdNodes as $node) {
            Log::logger()->debug('Attached node', ['id' => $node->id, 'parent' => $node->parentId]);
            $parentNode = $node->parentId === null ? null : ($this->nodes[$node->parentId] ?? null);
            if ($parentNode === null) {
                throw new SyncException('Parent of node ' . $node->id . ' not found');
            }
            $parentNode->append($node, false);
        }

        // Detach deleted nodes from the tree.
        foreach ($deletedNodes as $node) {
            $node->parent?->remove($node);
            unset($this->nodes[$node->id]);
            if ($node->serverId !== null) {
                unset($this->sidMap[$node->serverId]);
            }
            Log::logger()->debug('Deleted node', ['id' => $node->id]);
        }

        // Hydrate label references in notes.
        foreach ($this->all() as $node) {
            $node->labels->resolve($this->labels);
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function parseUserInfo(array $raw): void
    {
        $labels = [];
        if (array_key_exists('labels', $raw)) {
            $rawLabels = $raw['labels'];
            if (!is_array($rawLabels)) {
                throw new ParseException('Invalid labels', $raw);
            }
            foreach ($rawLabels as $label) {
                if (!is_array($label) || !isset($label['mainId']) || !is_string($label['mainId'])) {
                    throw new ParseException('Label without mainId', is_array($label) ? $label : []);
                }
                $mainId = $label['mainId'];

                // If the mainId field exists, this is an update.
                if (array_key_exists($mainId, $this->labels)) {
                    $node = $this->labels[$mainId];

                    // Remove this key from our list of labels.
                    unset($this->labels[$mainId]);
                    Log::logger()->debug('Updated label', ['id' => $mainId]);
                } else {
                    // Otherwise, this is a brand new label.
                    $node = new Label();
                    Log::logger()->debug('Created label', ['id' => $mainId]);
                }

                $node->load($label);
                $labels[$mainId] = $node;
            }
        }

        // All remaining labels are deleted.
        foreach (array_keys($this->labels) as $labelId) {
            Log::logger()->debug('Deleted label', ['id' => $labelId]);
        }

        $this->labels = $labels;
    }

    /**
     * @return list<Node>
     */
    private function findDirtyNodes(): array
    {
        // Find nodes that aren't in our internal nodes list and insert them.
        foreach (array_values($this->nodes) as $node) {
            foreach ($node->getChildren() as $child) {
                if (!array_key_exists($child->id, $this->nodes)) {
                    $this->nodes[$child->id] = $child;
                }
            }
        }

        // Collect all dirty nodes (any nodes from above will be caught too).
        return array_values(array_filter(
            $this->nodes,
            static fn (Node $node): bool => $node->isDirty(),
        ));
    }

    private function rememberServerId(Node $node): void
    {
        if ($node->serverId !== null) {
            $this->sidMap[$node->serverId] = $node->id;
        }
    }

    private function matchesQuery(TopLevelNode $node, Pattern|string|null $query): bool
    {
        if ($query === null) {
            return true;
        }
        if (is_string($query)) {
            return str_contains($node->getTitle(), $query) || str_contains($node->getText(), $query);
        }

        return $query->search($node->getTitle()) || $query->search($node->getText());
    }

    /**
     * @param list<string>|null $labelIds
     */
    private function matchesLabels(TopLevelNode $node, ?array $labelIds): bool
    {
        if ($labelIds === null) {
            return true;
        }
        if ($labelIds === []) {
            return $node->labels->all() === [];
        }
        foreach ($labelIds as $labelId) {
            if ($node->labels->get($labelId) instanceof Label) {
                return true;
            }
        }

        return false;
    }

    /** An id derived from the host name (upstream uses the MAC address). */
    private function defaultDeviceId(): string
    {
        return substr(hash('sha256', (string) gethostname()), 0, 12);
    }
}
