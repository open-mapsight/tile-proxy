<?php
declare(strict_types=1);

namespace OpenMapsight\TileProxy;

class Metadata
{
    private mixed $data = null;
    private bool $isDirty = false;

    public function __construct(private readonly ?string $filepath)
    {
    }

    public static function save(self $self): void
    {
        if ($self->data !== null && $self->isDirty) {
            file_put_contents($self->filepath, json_encode($self->data));
            $self->isDirty = false;
        }
    }

    public function __get($key)
    {
        if ($this->data === null) {
            self::forceLoadData($this);
        }

        return $this->data[$key] ?? null;
    }

    public function __set($key, $value)
    {
        if ($this->data === null) {
            self::forceLoadData($this);
        }

        $this->data[$key] = $value;
        $this->isDirty = true;
    }

    public static function forceLoadData(self $self): void
    {
        $str = @file_get_contents($self->filepath);
        if ($str === false) {
            $self->data = [];
        } else {
            $data = json_decode($str, true);
            if (is_array($data)) {
                $self->data = $data;
            } else {
                $self->data = [];
            }
        }
    }

    public function __isset($key): bool
    {
        if ($this->data === null) {
            self::forceLoadData($this);
        }

        return isset($this->data[$key]);
    }

    public function __unset($key)
    {
        if ($this->data === null) {
            self::forceLoadData($this);
        }

        unset($this->data[$key]);
        $this->isDirty = true;
    }
}
