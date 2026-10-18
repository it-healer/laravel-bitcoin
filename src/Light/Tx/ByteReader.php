<?php

namespace ItHealer\LaravelBitcoin\Light\Tx;

/**
 * Sequential reader of binary data that fails on reading past the end.
 */
class ByteReader
{
    protected int $offset = 0;

    public function __construct(protected readonly string $data)
    {
    }

    public function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new \InvalidArgumentException('Unexpected end of data.');
        }

        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }

    public function peek(int $length): string
    {
        return substr($this->data, $this->offset, $length);
    }

    public function uint32(): int
    {
        return unpack('V', $this->read(4))[1];
    }

    public function uint64(): int
    {
        return unpack('P', $this->read(8))[1];
    }

    public function varInt(): int
    {
        $first = ord($this->read(1));

        return match ($first) {
            0xfd => unpack('v', $this->read(2))[1],
            0xfe => unpack('V', $this->read(4))[1],
            0xff => unpack('P', $this->read(8))[1],
            default => $first,
        };
    }

    public function eof(): bool
    {
        return $this->offset === strlen($this->data);
    }
}
