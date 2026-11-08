<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Represents the timestamps associated with a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `NodeTimestamps` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeTimestamps extends Element
{
    public const TZ_FMT = 'Y-m-d\TH:i:s.u\Z';

    private DateTimeImmutable $created;
    private ?DateTimeImmutable $deleted = null;
    private ?DateTimeImmutable $trashed = null;
    private DateTimeImmutable $updated;
    private ?DateTimeImmutable $edited;

    /** @param float|int|null $createTime Unix timestamp; defaults to now. */
    public function __construct(float|int|null $createTime = null)
    {
        $createTime ??= microtime(true);

        $this->created = self::intToDt($createTime);
        $this->updated = self::intToDt($createTime);
        $this->edited = self::intToDt($createTime);
    }

    /**
     * Convert a datetime string into an object.
     *
     * @throws \ValueError If the string is not a valid Keep timestamp.
     */
    public static function strToDt(?string $tzs): DateTimeImmutable
    {
        if ($tzs === null) {
            return self::intToDt(0);
        }

        $dt = DateTimeImmutable::createFromFormat(self::TZ_FMT, $tzs, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \ValueError('Invalid timestamp');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    /** Convert a unix timestamp into an object. */
    public static function intToDt(float|int $tz): DateTimeImmutable
    {
        $sec = (int) floor($tz);
        $usec = (int) round(($tz - $sec) * 1_000_000);
        if ($usec >= 1_000_000) {
            $sec++;
            $usec -= 1_000_000;
        }

        $dt = DateTimeImmutable::createFromFormat('U u', sprintf('%d %06d', $sec, $usec));
        if ($dt === false) {
            throw new \ValueError('Invalid unix timestamp');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    /** Convert a datetime to a string. */
    public static function dtToStr(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::TZ_FMT);
    }

    /** Convert a unix timestamp to a string. */
    public static function intToStr(float|int $tz): string
    {
        return self::dtToStr(self::intToDt($tz));
    }

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['kind'] = 'notes#timestamps';
        $ret['created'] = self::dtToStr($this->created);
        if ($this->deleted instanceof \DateTimeImmutable) {
            $ret['deleted'] = self::dtToStr($this->deleted);
        }
        if ($this->trashed instanceof \DateTimeImmutable) {
            $ret['trashed'] = self::dtToStr($this->trashed);
        }
        $ret['updated'] = self::dtToStr($this->updated);
        if ($this->edited instanceof \DateTimeImmutable) {
            $ret['userEdited'] = self::dtToStr($this->edited);
        }

        return $ret;
    }

    /** Get the creation datetime. */
    public function getCreated(): DateTimeImmutable
    {
        return $this->created;
    }

    /** Set the creation datetime. */
    public function setCreated(DateTimeImmutable $value): void
    {
        $this->created = $value;
        $this->dirty = true;
    }

    /** Get the deletion datetime. */
    public function getDeleted(): ?DateTimeImmutable
    {
        return $this->deleted;
    }

    /** Set the deletion datetime. */
    public function setDeleted(?DateTimeImmutable $value): void
    {
        $this->deleted = $value;
        $this->dirty = true;
    }

    /** Get the move-to-trash datetime. */
    public function getTrashed(): ?DateTimeImmutable
    {
        return $this->trashed;
    }

    /** Set the move-to-trash datetime. */
    public function setTrashed(?DateTimeImmutable $value): void
    {
        $this->trashed = $value;
        $this->dirty = true;
    }

    /** Get the updated datetime. */
    public function getUpdated(): DateTimeImmutable
    {
        return $this->updated;
    }

    /** Set the updated datetime. */
    public function setUpdated(DateTimeImmutable $value): void
    {
        $this->updated = $value;
        $this->dirty = true;
    }

    /** Get the user edited datetime. */
    public function getEdited(): ?DateTimeImmutable
    {
        return $this->edited;
    }

    /** Set the user edited datetime. */
    public function setEdited(?DateTimeImmutable $value): void
    {
        $this->edited = $value;
        $this->dirty = true;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        if (array_key_exists('created', $raw)) {
            $this->created = self::strToDt(self::strOrNull($raw['created']));
        }
        $this->deleted = array_key_exists('deleted', $raw)
            ? self::strToDt(self::strOrNull($raw['deleted'])) : null;
        $this->trashed = array_key_exists('trashed', $raw)
            ? self::strToDt(self::strOrNull($raw['trashed'])) : null;
        $this->updated = self::strToDt(self::strOrNull(self::req($raw, 'updated')));
        $this->edited = array_key_exists('userEdited', $raw)
            ? self::strToDt(self::strOrNull($raw['userEdited'])) : null;
    }
}
