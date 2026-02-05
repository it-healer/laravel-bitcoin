<?php

declare(strict_types=1);

namespace ItHealer\LaravelBitcoin\BIP39;

/**
 * Simple Buffer implementation to replace Charcoal\Buffers
 */
class Buffer
{
    private string $data;

    /**
     * @param string $data Raw binary data
     */
    public function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * Get length in bytes
     *
     * @return int
     */
    public function len(): int
    {
        return strlen($this->data);
    }

    /**
     * Convert to hexadecimal string
     *
     * @return string
     */
    public function toBase16(): string
    {
        return bin2hex($this->data);
    }

    /**
     * Get raw binary data
     *
     * @return string
     */
    public function raw(): string
    {
        return $this->data;
    }
}
