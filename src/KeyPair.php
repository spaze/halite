<?php
declare(strict_types=1);
namespace ParagonIE\Halite;

use ParagonIE\Halite\Asymmetric\{
    PublicKey,
    SecretKey
};

/**
 * Class KeyPair
 *
 * Describes a pair of secret and public keys
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
class KeyPair
{
    protected SecretKey $secretKey;
    protected PublicKey $publicKey;

    /**
     * Hide this from var_dump(), etc.
     * 
     * @return array
     * @codeCoverageIgnore
     */
    public function __debugInfo()
    {
        return [
            'privateKey' => '**protected**',
            'publicKey' => '**protected**'
        ];
    }

    /**
     * Get a Key object for the public key
     * 
     * @return PublicKey
     * @codeCoverageIgnore
     */
    public function getPublicKey()
    {
       return $this->publicKey;
    }

    /**
     * Get a Key object for the secret key
     * 
     * @return SecretKey
     * @codeCoverageIgnore
     */
    public function getSecretKey()
    {
       return $this->secretKey;
    }
}
