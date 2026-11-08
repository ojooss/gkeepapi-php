<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use Gkeepapi\Exception\ParseException;

/**
 * Interface for elements that can be serialized and deserialized.
 *
 * Port of gkeepapi/node.py `Element` (MIT, (c) Kai Zhong / kiwiz).
 */
class Element
{
    /** Whether this element has unsaved changes. */
    protected bool $dirty = false;

    /**
     * Unserialize from raw representation. (Wrapper)
     *
     * @param array<array-key, mixed> $raw Raw.
     * @throws ParseException If there was an error parsing data.
     */
    public function load(array $raw): void
    {
        try {
            $this->doLoad($raw);
        } catch (\OutOfBoundsException | \ValueError $e) {
            throw new ParseException('Parse error in ' . static::class, $raw, $e);
        }
    }

    /**
     * Serialize into raw representation. Clears the dirty bit by default.
     *
     * @param bool $clean Whether to clear the dirty bit.
     * @return array<array-key, mixed> Raw.
     */
    public function save(bool $clean = true): array
    {
        $ret = [];
        if ($clean) {
            $this->dirty = false;
        } else {
            $ret['_dirty'] = $this->dirty;
        }

        return $ret;
    }

    /** Get dirty state. */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Unserialize from raw representation. (Implementation logic)
     *
     * @param array<array-key, mixed> $raw Raw.
     */
    protected function doLoad(array $raw): void
    {
        $this->dirty = (bool) self::opt($raw, '_dirty', false);
    }

    /**
     * Get a required key (Python `raw[key]`).
     *
     * @param array<array-key, mixed> $raw
     * @throws \OutOfBoundsException If the key is missing.
     */
    protected static function req(array $raw, string $key): mixed
    {
        if (!array_key_exists($key, $raw)) {
            throw new \OutOfBoundsException('Missing key ' . $key);
        }

        return $raw[$key];
    }

    /**
     * Get an optional key (Python `raw.get(key, default)`); a present null value is returned as null.
     *
     * @param array<array-key, mixed> $raw
     */
    protected static function opt(array $raw, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $raw) ? $raw[$key] : $default;
    }

    /**
     * Get a required key that must hold an array.
     *
     * @param array<array-key, mixed> $raw
     * @return array<array-key, mixed>
     * @throws \OutOfBoundsException If the key is missing.
     * @throws \ValueError If the value is not an array.
     */
    protected static function reqArray(array $raw, string $key): array
    {
        $value = self::req($raw, $key);
        if (!is_array($value)) {
            throw new \ValueError('Expected array for key ' . $key);
        }

        return $value;
    }

    /**
     * Get an optional key that must hold an array if present.
     *
     * @param array<array-key, mixed> $raw
     * @return array<array-key, mixed>
     * @throws \ValueError If the value is present but not an array.
     */
    protected static function optArray(array $raw, string $key): array
    {
        $value = self::opt($raw, $key, []);
        if (!is_array($value)) {
            throw new \ValueError('Expected array for key ' . $key);
        }

        return $value;
    }

    /**
     * Convert a scalar to string.
     *
     * @throws \ValueError If the value is not a scalar.
     */
    protected static function str(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \ValueError('Expected scalar');
        }

        return (string) $value;
    }

    /**
     * Convert a scalar or null to ?string.
     *
     * @throws \ValueError If the value is neither scalar nor null.
     */
    protected static function strOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    /**
     * Convert a scalar or null to ?int.
     *
     * @throws \ValueError If the value is not numeric or null.
     */
    protected static function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \ValueError('Expected number');
        }

        return (int) $value;
    }
}
