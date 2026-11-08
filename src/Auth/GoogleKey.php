<?php

declare(strict_types=1);

namespace Gkeepapi\Auth;

use Gkeepapi\Exception\LoginException;

/**
 * The RSA public key distributed with Google Play Services, used to encrypt login credentials.
 *
 * Port of gpsoauth/google.py `key_from_b64`, `key_to_struct` and the encryption part of `construct_signature`
 * (MIT, (c) 2015 Simon Weber).
 */
final readonly class GoogleKey
{
    /** Key from Google Play Services 7.3.29 (base64, Android key struct format). */
    public const B64_KEY_7_3_29 = 'AAAAgMom/1a/v0lblO2Ubrt60J2gcuXSljGFQXgcyZWveWLEwo6prwgi3'
        . 'iJIZdodyhKZQrNWp5nKJ3srRXcUW+F1BD3baEVGcmEgqaLZUNBjm057pK'
        . 'RI16kB0YppeGx5qIQ5QjKzsR8ETQbKLNWgRY0QRNVz34kMJR3P/LgHax/'
        . '6rmf5AAAAAwEAAQ==';

    /**
     * @param string $modulus Big-endian modulus bytes without leading zeros.
     * @param string $exponent Big-endian public exponent bytes without leading zeros.
     */
    public function __construct(
        public string $modulus,
        public string $exponent,
    ) {
    }

    /** The default key shipped with Google Play Services 7.3.29. */
    public static function android(): self
    {
        return self::fromBase64(self::B64_KEY_7_3_29);
    }

    /**
     * Extract a key from its base64 representation.
     *
     * @throws \ValueError If the data is not a valid key struct.
     */
    public static function fromBase64(string $b64Key): self
    {
        $binary = base64_decode($b64Key, true);
        if ($binary === false || strlen($binary) < 8) {
            throw new \ValueError('Invalid base64 key');
        }

        $i = self::bytesToInt(substr($binary, 0, 4));
        $modulus = substr($binary, 4, $i);
        $j = self::bytesToInt(substr($binary, $i + 4, 4));
        $exponent = substr($binary, $i + 8, $j);
        if (strlen($modulus) !== $i || $i === 0 || strlen($exponent) !== $j || $j === 0) {
            throw new \ValueError('Truncated key');
        }

        return new self(self::trim($modulus), self::trim($exponent));
    }

    /**
     * Convert the key to the struct whose SHA-1 prefix identifies the key in the signature.
     *
     * Like upstream, the length fields are the constants 128 and 3.
     */
    public function toStruct(): string
    {
        return "\x00\x00\x00\x80" . $this->modulus . "\x00\x00\x00\x03" . $this->exponent;
    }

    /** The key as PEM-encoded SubjectPublicKeyInfo. */
    public function toPem(): string
    {
        $rsaPublicKey = $this->der(0x30, $this->derInteger($this->modulus) . $this->derInteger($this->exponent));
        $algorithm = $this->der(0x30, $this->der(0x06, hex2bin('2a864886f70d010101') ?: '') . $this->der(0x05, ''));
        $spki = $this->der(0x30, $algorithm . $this->der(0x03, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Encrypt with RSA-OAEP (SHA-1, MGF1-SHA-1).
     *
     * @throws LoginException If the encryption fails.
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $publicKey = openssl_pkey_get_public($this->toPem());
        if ($publicKey === false) {
            throw new LoginException('Invalid public key');
        }
        $encrypted = '';
        if (
            !openssl_public_encrypt($plaintext, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)
            || !is_string($encrypted)
        ) {
            throw new LoginException('Could not encrypt credentials');
        }

        return $encrypted;
    }

    private static function bytesToInt(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    private static function trim(string $bytes): string
    {
        $trimmed = ltrim($bytes, "\x00");

        return $trimmed === '' ? "\x00" : $trimmed;
    }

    private function derInteger(string $bytes): string
    {
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return $this->der(0x02, $bytes);
    }

    private function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            $encoded = chr($length);
        } else {
            $lengthBytes = ltrim(pack('N', $length), "\x00");
            $encoded = chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
        }

        return chr($tag) . $encoded . $content;
    }
}
