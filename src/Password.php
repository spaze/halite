<?php
declare(strict_types=1);
namespace ParagonIE\Halite;

use ParagonIE\ConstantTime\{
    Base64UrlSafe,
    Binary,
    Hex
};
use ParagonIE\Halite\Alerts\{
    CannotPerformOperation,
    InvalidDigestLength,
    InvalidMessage,
    InvalidSignature,
    InvalidType
};
use ParagonIE\Halite\Symmetric\{
    Config as SymmetricConfig,
    Crypto,
    EncryptionKey
};
use ParagonIE\HiddenString\HiddenString;
use SodiumException;
use TypeError;
use const SODIUM_CRYPTO_PWHASH_STRPREFIX;
use function
    hash_equals,
    sodium_crypto_pwhash_str,
    sodium_crypto_pwhash_str_verify;

/**
 * Class Password
 *
 * Secure password storage and secure password verification
 *
 * This library makes heavy use of return-type declarations,
 * which are a PHP 7 only feature. Read more about them here:
 *
 * @ref https://www.php.net/manual/en/functions.returning-values.php#functions.returning-values.type-declaration
 *
 * @package ParagonIE\Halite
 *
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://www.mozilla.org/en-US/MPL/2.0/.
 */
final class Password
{
    /**
     * Hash then encrypt a password
     *
     * @param HiddenString $password    The user's password
     * @param EncryptionKey $secretKey  The master key for all passwords
     * @param string $level             The security level for this password
     * @param string $additionalData    Additional authenticated data
     *
     * @return string                   An encrypted hash to store
     *
     * @throws CannotPerformOperation
     * @throws InvalidDigestLength
     * @throws InvalidMessage
     * @throws InvalidType
     * @throws SodiumException
     * @throws TypeError
     */
    public static function hash(
        #[\SensitiveParameter]
        HiddenString $password,
        #[\SensitiveParameter]
        EncryptionKey $secretKey,
        string $level = KeyFactory::INTERACTIVE,
        #[\SensitiveParameter]
        string $additionalData = ''
    ): string {
        $kdfLimits = KeyFactory::getSecurityLevels($level);
        // First, let's calculate the hash
        $hashed = sodium_crypto_pwhash_str(
            $password->getString(),
            $kdfLimits[0],
            $kdfLimits[1]
        );
        
        // Now let's encrypt the result
        return Crypto::encryptWithAd(
            new HiddenString($hashed),
            $secretKey,
            $additionalData
        );
    }

    /**
     * Is this password hash stale?
     *
     * @param string $stored            Encrypted password hash
     * @param EncryptionKey $secretKey  The master key for all passwords
     * @param string $level             The security level for this password
     * @param string $additionalData    Additional authenticated data (if used to encrypt, mandatory)
     *
     * @return bool                     Do we need to regenerate the hash or
     *                                  ciphertext?
     *
     * @throws CannotPerformOperation
     * @throws InvalidDigestLength
     * @throws InvalidMessage
     * @throws InvalidSignature
     * @throws InvalidType
     * @throws SodiumException
     * @throws TypeError
     */
    public static function needsRehash(
        #[\SensitiveParameter]
        string $stored,
        #[\SensitiveParameter]
        EncryptionKey $secretKey,
        string $level = KeyFactory::INTERACTIVE,
        #[\SensitiveParameter]
        string $additionalData = ''
    ): bool {
        $config = self::getConfig($stored);
        if (Binary::safeStrlen($stored) < ((int) $config->SHORTEST_CIPHERTEXT_LENGTH * 4 / 3)) {
            throw new InvalidMessage('Encrypted password hash is too short.');
        }
        $encoding = $config->ENCODING;

        // First let's decrypt the hash
        $hash_str = Crypto::decryptWithAd(
            $stored,
            $secretKey,
            $additionalData,
            $encoding
        )->getString();

        // Upon successful decryption, verify that we're using Argon2id
        if (!hash_equals(
            Binary::safeSubstr($hash_str, 0, 10),
            SODIUM_CRYPTO_PWHASH_STRPREFIX
        )) {
            return true;
        }

        // Parse the cost parameters:
        return match ($level) {
            KeyFactory::INTERACTIVE => !hash_equals(
                '$argon2id$v=19$m=65536,t=2,p=1$',
                Binary::safeSubstr($hash_str, 0, 31)
            ),
            KeyFactory::MODERATE => !hash_equals(
                '$argon2id$v=19$m=262144,t=3,p=1$',
                Binary::safeSubstr($hash_str, 0, 32)
            ),
            KeyFactory::SENSITIVE => !hash_equals(
                '$argon2id$v=19$m=1048576,t=4,p=1$',
                Binary::safeSubstr($hash_str, 0, 33)
            ),
            default => true,
        };
    }

    /**
     * Get the configuration for this version of halite
     *
     * @param string $stored   A stored password hash
     * @return SymmetricConfig
     * @throws InvalidMessage
     * @throws \TypeError
     */
    protected static function getConfig(string $stored): SymmetricConfig
    {
        $length = Binary::safeStrlen($stored);
        // This doesn't even have a header.
        if ($length < 8) {
            throw new InvalidMessage(
                'Encrypted password hash is way too short.'
            );
        }
        $prefix = Binary::safeSubstr($stored, 0, 5);
        if (
            hash_equals($prefix, Halite::VERSION_PREFIX)
                ||
            hash_equals($prefix, Halite::VERSION_OLD_PREFIX)
        ) {
            $decoded = Base64UrlSafe::decode($stored);
            return SymmetricConfig::getConfig($decoded, 'encrypt');
        }

        // @codeCoverageIgnoreStart
        $v = Hex::decode(Binary::safeSubstr($stored, 0, 8));
        return SymmetricConfig::getConfig($v, 'encrypt');
        // @codeCoverageIgnoreEnd
    }

    /**
     * Decrypt then verify a password
     *
     * @param HiddenString $password    The user's password
     * @param string $stored            The encrypted password hash
     * @param EncryptionKey $secretKey  The master key for all passwords
     * @param string $additionalData    Additional authenticated data (needed to decrypt)
     *
     * @return bool                     Is this password valid?
     *
     * @throws Alerts\InvalidSignature
     * @throws CannotPerformOperation
     * @throws InvalidDigestLength
     * @throws InvalidMessage
     * @throws InvalidType
     * @throws SodiumException
     * @throws TypeError
     */
    public static function verify(
        #[\SensitiveParameter]
        HiddenString $password,
        #[\SensitiveParameter]
        string $stored,
        #[\SensitiveParameter]
        EncryptionKey $secretKey,
        #[\SensitiveParameter]
        string $additionalData = ''
    ): bool {
        $config = self::getConfig($stored);
        // Base64-urlsafe encoded, so 4/3 the size of raw binary
        if (Binary::safeStrlen($stored) < ((int) $config->SHORTEST_CIPHERTEXT_LENGTH * 4/3)) {
            throw new InvalidMessage(
                'Encrypted password hash is too short.'
            );
        }
        $encoding = $config->ENCODING;

        // First let's decrypt the hash
        $hash_str = Crypto::decryptWithAd($stored, $secretKey, $additionalData, $encoding);
        // Upon successful decryption, verify the password is correct
        return sodium_crypto_pwhash_str_verify(
            $hash_str->getString(),
            $password->getString()
        );
    }
}
