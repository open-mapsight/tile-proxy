<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class MetadataScope
{
    public function __construct(private Metadata|MetadataScope $ref, private readonly string $keyPrefix)
    {
    }

    public static function save(self $self): void
    {
        Metadata::save($self->getRootMetadata());
    }

    private function getRootMetadata(): Metadata
    {
        $ref = $this->ref;
        while ($ref instanceof MetadataScope) {
            $ref = $ref->ref;
        }

        return $ref;
    }

    public function __get(string $key)
    {
        return $this->ref->__get($this->keyPrefix . '|' . $key);
    }

    public function __set(string $key, mixed $value)
    {
        $this->ref->__set($this->keyPrefix . '|' . $key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->ref->__isset($this->keyPrefix . '|' . $key);
    }

    public function __unset(string $key)
    {
        $this->ref->__unset($this->keyPrefix . '|' . $key);
    }

    public function getLast4xx(): ?int
    {
        $value = $this->__get('last4xx');
        return is_int($value) ? $value : null;
    }

    public function setLast4xx(int $timestamp): void
    {
        $this->__set('last4xx', $timestamp);
    }

    public function getLast5xx(): ?int
    {
        $value = $this->__get('last5xx');
        return is_int($value) ? $value : null;
    }

    public function setLast5xx(int $timestamp): void
    {
        $this->__set('last5xx', $timestamp);
    }
}
