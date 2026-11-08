<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents the collaborators on a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `NodeCollaborators` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeCollaborators extends Element implements \Countable
{
    /** @var array<string, RoleValue|ShareRequestValue> */
    private array $collaborators = [];

    public function count(): int
    {
        return count($this->collaborators);
    }

    /**
     * Unserialize from the raw collaborators and share requests.
     *
     * The first argument is the raw collaborator list; `$requestsRaw` the raw share requests, which may carry
     * a trailing bool holding the dirty state. The parent implementation is deliberately not used.
     *
     * @param array<array-key, mixed> $raw Raw collaborator list (`roleInfo`).
     * @param array<array-key, mixed> $requestsRaw Raw share requests (`shareRequests`).
     */
    public function load(array $raw, array $requestsRaw = []): void
    {
        $requests = array_values($requestsRaw);
        $last = end($requests);
        if ($requests !== [] && is_bool($last)) {
            $this->dirty = $last;
            array_pop($requests);
        } else {
            $this->dirty = false;
        }

        $this->collaborators = [];
        foreach ($raw as $collaborator) {
            if (!is_array($collaborator) || ($collaborator['email'] ?? null) === null) {
                continue;
            }
            $this->collaborators[self::str($collaborator['email'])] = RoleValue::from(
                self::str(self::req($collaborator, 'role')),
            );
        }
        foreach ($requests as $collaborator) {
            if (!is_array($collaborator) || ($collaborator['email'] ?? null) === null) {
                continue;
            }
            $this->collaborators[self::str($collaborator['email'])] = ShareRequestValue::from(
                self::str(self::req($collaborator, 'type')),
            );
        }
    }

    /**
     * Save the collaborators container.
     *
     * @return array{0: list<array<string, string>>, 1: list<array<string, mixed>|bool>} Tuple of the collaborator
     *         list and the share request list (with a trailing dirty bool when not cleaning).
     */
    public function save(bool $clean = true): array
    {
        $collaborators = [];
        $requests = [];
        foreach ($this->collaborators as $email => $action) {
            if ($action instanceof ShareRequestValue) {
                $requests[] = ['email' => (string) $email, 'type' => $action->value];
            } else {
                $collaborators[] = ['email' => (string) $email, 'role' => $action->value, 'auxiliary_type' => 'None'];
            }
        }
        if (!$clean) {
            $requests[] = $this->dirty;
        } else {
            $this->dirty = false;
        }

        return [$collaborators, $requests];
    }

    /** Add a collaborator. */
    public function add(string $email): void
    {
        if (!array_key_exists($email, $this->collaborators)) {
            $this->collaborators[$email] = ShareRequestValue::Add;
        }
        $this->dirty = true;
    }

    /** Remove a collaborator. */
    public function remove(string $email): void
    {
        if (array_key_exists($email, $this->collaborators)) {
            if ($this->collaborators[$email] === ShareRequestValue::Add) {
                unset($this->collaborators[$email]);
            } else {
                $this->collaborators[$email] = ShareRequestValue::Remove;
            }
        }
        $this->dirty = true;
    }

    /**
     * Get all collaborators.
     *
     * @return list<string> Collaborator email addresses.
     */
    public function all(): array
    {
        $emails = [];
        foreach ($this->collaborators as $email => $action) {
            if ($action !== ShareRequestValue::Remove) {
                $emails[] = (string) $email;
            }
        }

        return $emails;
    }
}
