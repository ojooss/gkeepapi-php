<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Auth;

use Gkeepapi\Auth\GoogleKey;
use Gkeepapi\Auth\GpsOAuth;
use PHPUnit\Framework\TestCase;

final class GoogleKeyTest extends TestCase
{
    public function testAndroidKeyParsesToA1024BitModulusAndExponent65537(): void
    {
        $key = GoogleKey::android();

        self::assertSame(128, strlen($key->modulus));
        self::assertSame("\x01\x00\x01", $key->exponent);
    }

    public function testStructEqualsTheDecodedKeyBlob(): void
    {
        $key = GoogleKey::android();

        self::assertSame(base64_decode(GoogleKey::B64_KEY_7_3_29, true), $key->toStruct());
    }

    public function testInvalidKeysAreRejected(): void
    {
        $this->expectException(\ValueError::class);

        GoogleKey::fromBase64('AAAA');
    }

    public function testPemIsAValidRsaPublicKey(): void
    {
        $key = GoogleKey::android();

        $details = openssl_pkey_get_details($this->publicKey($key));

        self::assertIsArray($details);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertSame(1024, $details['bits']);
        self::assertIsArray($details['rsa']);
        self::assertSame($key->modulus, $details['rsa']['n']);
        self::assertSame($key->exponent, $details['rsa']['e']);
    }

    public function testEncryptedPayloadCanBeDecryptedWithOaepSha1(): void
    {
        [$key, $private] = $this->generatedKey();

        $encrypted = $key->encrypt("user@example.com\x00secret");

        $plain = '';
        self::assertTrue(openssl_private_decrypt($encrypted, $plain, $private, OPENSSL_PKCS1_OAEP_PADDING));
        self::assertSame("user@example.com\x00secret", $plain);
    }

    public function testSignatureLayoutMatchesGpsoauth(): void
    {
        [$key, $private] = $this->generatedKey();

        $signature = GpsOAuth::constructSignature('user@example.com', 'secret', $key);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_=-]+$/', $signature);
        $binary = base64_decode(strtr($signature, '-_', '+/'), true);
        self::assertIsString($binary);
        self::assertSame("\x00", $binary[0]);
        self::assertSame(substr(sha1($key->toStruct(), true), 0, 4), substr($binary, 1, 4));
        $plain = '';
        self::assertTrue(openssl_private_decrypt(substr($binary, 5), $plain, $private, OPENSSL_PKCS1_OAEP_PADDING));
        self::assertSame("user@example.com\x00secret", $plain);
    }

    public function testSignatureIsRandomizedPerCall(): void
    {
        $key = GoogleKey::android();

        self::assertNotSame(
            GpsOAuth::constructSignature('a@example.com', 'x', $key),
            GpsOAuth::constructSignature('a@example.com', 'x', $key),
        );
    }

    private function publicKey(GoogleKey $key): \OpenSSLAsymmetricKey
    {
        $public = openssl_pkey_get_public($key->toPem());
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $public);

        return $public;
    }

    /** @return array{GoogleKey, \OpenSSLAsymmetricKey} */
    private function generatedKey(): array
    {
        $private = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa']);

        self::assertIsString($details['rsa']['n']);
        self::assertIsString($details['rsa']['e']);

        return [new GoogleKey($details['rsa']['n'], $details['rsa']['e']), $private];
    }
}
