<?php

namespace ItHealer\LaravelBitcoin\Light\Keys;

use BN\BN;
use Elliptic\EC;
use ItHealer\LaravelBitcoin\Light\Encoding\Base58;
use ItHealer\LaravelBitcoin\Light\Encoding\Bech32;

/**
 * BIP32 extended key. The private part is used only to get from a seed to the account key
 * (hardened path), the server keeps the public account key (xpub) and derives addresses from
 * it with public (non-hardened) derivation.
 */
class ExtendedKey
{
    public const HARDENED = 0x80000000;

    /** Mainnet version bytes: xpub/xprv (BIP32), ypub (BIP49), zpub (BIP84) */
    protected const PUBLIC_VERSIONS = ['0488b21e' => 'xpub', '049d7cb2' => 'ypub', '04b24746' => 'zpub'];

    protected const XPRV_VERSION = '0488ade4';

    protected static ?EC $curve = null;

    protected function __construct(
        public readonly int $depth,
        public readonly string $parentFingerprint,
        public readonly int $childNumber,
        public readonly string $chainCode,
        public readonly string $publicKey,
        #[\SensitiveParameter]
        protected readonly ?string $privateKey = null,
    ) {
    }

    public static function fromSeed(#[\SensitiveParameter] string $seed): self
    {
        $hash = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $privateKey = substr($hash, 0, 32);

        self::assertValidPrivateKey($privateKey);

        return new self(0, "\0\0\0\0", 0, substr($hash, 32), self::publicFromPrivate($privateKey), $privateKey);
    }

    /**
     * Parses an xpub, ypub or zpub (mainnet). The version only tells the wallet software which
     * script type to use; the key material is the same.
     */
    public static function fromPublicString(string $encoded): self
    {
        $payload = Base58::decodeCheck($encoded);

        if (strlen($payload) !== 78 || !isset(self::PUBLIC_VERSIONS[bin2hex(substr($payload, 0, 4))])) {
            throw new \InvalidArgumentException('Not a mainnet extended public key.');
        }

        $publicKey = substr($payload, 45, 33);

        if (!in_array($publicKey[0], ["\x02", "\x03"], true)) {
            throw new \InvalidArgumentException('Invalid extended public key.');
        }

        self::curve()->curve->decodePoint(bin2hex($publicKey), 'hex');

        return new self(
            ord($payload[4]),
            substr($payload, 5, 4),
            unpack('N', substr($payload, 9, 4))[1],
            substr($payload, 13, 32),
            $publicKey,
        );
    }

    /**
     * Derivation path like m/84'/0'/0' (h and H mean hardened too).
     */
    public function derivePath(string $path): self
    {
        $segments = explode('/', trim($path));

        if (array_shift($segments) !== 'm') {
            throw new \InvalidArgumentException('Derivation path must start with m.');
        }

        $key = $this;

        foreach ($segments as $segment) {
            if (!preg_match("/^(\\d+)(['hH]?)$/", $segment, $match) || (int)$match[1] >= self::HARDENED) {
                throw new \InvalidArgumentException('Invalid derivation path segment: '.$segment);
            }

            $key = $key->deriveChild((int)$match[1] + ($match[2] !== '' ? self::HARDENED : 0));
        }

        return $key;
    }

    public function deriveChild(int $index): self
    {
        $hardened = $index >= self::HARDENED;

        if ($hardened && $this->privateKey === null) {
            throw new \LogicException('Hardened derivation needs the private key.');
        }

        $data = $hardened
            ? "\0".$this->privateKey.pack('N', $index)
            : $this->publicKey.pack('N', $index);

        $hash = hash_hmac('sha512', $data, $this->chainCode, true);
        $tweak = substr($hash, 0, 32);
        $chainCode = substr($hash, 32);
        $order = self::curve()->n;
        $tweakNumber = new BN(bin2hex($tweak), 16);

        if ($tweakNumber->cmp($order) >= 0) {
            throw new \RuntimeException('Invalid child key, use the next index.');
        }

        if ($this->privateKey !== null) {
            $childNumber = $tweakNumber->add(new BN(bin2hex($this->privateKey), 16))->umod($order);

            if ($childNumber->isZero()) {
                throw new \RuntimeException('Invalid child key, use the next index.');
            }

            $childPrivate = hex2bin(str_pad($childNumber->toString(16), 64, '0', STR_PAD_LEFT));

            return new self($this->depth + 1, $this->fingerprint(), $index, $chainCode, self::publicFromPrivate($childPrivate), $childPrivate);
        }

        $point = self::curve()->g->mul($tweakNumber)->add(self::curve()->curve->decodePoint(bin2hex($this->publicKey), 'hex'));

        if ($point->isInfinity()) {
            throw new \RuntimeException('Invalid child key, use the next index.');
        }

        return new self($this->depth + 1, $this->fingerprint(), $index, $chainCode, hex2bin($point->encode('hex', true)));
    }

    /**
     * The same key without the private part.
     */
    public function neutered(): self
    {
        return new self($this->depth, $this->parentFingerprint, $this->childNumber, $this->chainCode, $this->publicKey);
    }

    public function hasPrivateKey(): bool
    {
        return $this->privateKey !== null;
    }

    public function toXpub(): string
    {
        return $this->serialize('0488b21e', $this->publicKey);
    }

    public function toXprv(): string
    {
        if ($this->privateKey === null) {
            throw new \LogicException('No private key.');
        }

        return $this->serialize(self::XPRV_VERSION, "\0".$this->privateKey);
    }

    /**
     * First 4 bytes of HASH160 of the public key (BIP32 key identifier).
     */
    public function fingerprint(): string
    {
        return substr(self::hash160($this->publicKey), 0, 4);
    }

    /**
     * Native segwit v0 address of this key (P2WPKH, BIP84).
     */
    public function p2wpkhAddress(string $hrp = 'bc'): string
    {
        return Bech32::encodeSegwit($hrp, 0, self::hash160($this->publicKey));
    }

    public static function hash160(string $data): string
    {
        return hash('ripemd160', hash('sha256', $data, true), true);
    }

    protected function serialize(string $version, string $keyData): string
    {
        return Base58::encodeCheck(
            hex2bin($version).chr($this->depth).$this->parentFingerprint.pack('N', $this->childNumber).$this->chainCode.$keyData
        );
    }

    protected static function publicFromPrivate(#[\SensitiveParameter] string $privateKey): string
    {
        return hex2bin(self::curve()->keyFromPrivate(bin2hex($privateKey), 'hex')->getPublic(true, 'hex'));
    }

    protected static function assertValidPrivateKey(#[\SensitiveParameter] string $privateKey): void
    {
        $number = new BN(bin2hex($privateKey), 16);

        if ($number->isZero() || $number->cmp(self::curve()->n) >= 0) {
            throw new \RuntimeException('Invalid master key.');
        }
    }

    protected static function curve(): EC
    {
        return self::$curve ??= new EC('secp256k1');
    }
}
