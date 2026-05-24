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
        $self->ref->save($self->ref);
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
}
