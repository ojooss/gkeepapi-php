<?php

declare(strict_types=1);

namespace Gkeepapi\Auth;

use Gkeepapi\Exception\BrowserLoginRequiredException;
use Gkeepapi\Exception\LoginException;

/**
 * Authentication token manager.
 *
 * Port of gkeepapi/__init__.py `APIAuth` (MIT, (c) Kai Zhong / kiwiz).
 */
class APIAuth
{
    private ?string $masterToken = null;
    private ?string $authToken = null;
    private ?string $email = null;
    private ?string $deviceId = null;

    /**
     * @param string $scopes The OAuth scopes to request.
     */
    public function __construct(
        private readonly string $scopes,
        private readonly GpsOAuth $gpsOAuth,
    ) {
    }

    /**
     * Authenticate to Google with the provided credentials.
     *
     * @param string $email The account to use.
     * @param string $password The account password.
     * @param string $deviceId An identifier for this client.
     * @throws BrowserLoginRequiredException If a login in the browser is required.
     * @throws LoginException If there was a problem logging in.
     */
    public function login(string $email, #[\SensitiveParameter] string $password, string $deviceId): void
    {
        $this->email = $email;
        $this->deviceId = $deviceId;

        // Obtain a master token.
        $res = $this->gpsOAuth->performMasterLogin($email, $password, $deviceId);

        // Bail if browser login is required.
        if (($res['Error'] ?? null) === 'NeedsBrowser') {
            throw new BrowserLoginRequiredException($res['Url'] ?? '');
        }

        // Bail if no token was returned.
        if (!array_key_exists('Token', $res)) {
            throw new LoginException($this->errorMessage($res));
        }

        $this->masterToken = $res['Token'];

        // Obtain an OAuth token.
        $this->refresh();
    }

    /**
     * Authenticate to Google with the provided master token.
     *
     * @param string $email The account to use.
     * @param string $masterToken The master token.
     * @param string $deviceId An identifier for this client.
     * @throws LoginException If there was a problem logging in.
     */
    public function load(string $email, #[\SensitiveParameter] string $masterToken, string $deviceId): bool
    {
        $this->email = $email;
        $this->deviceId = $deviceId;
        $this->masterToken = $masterToken;

        // Obtain an OAuth token.
        $this->refresh();

        return true;
    }

    /** Gets the master token. */
    public function getMasterToken(): ?string
    {
        return $this->masterToken;
    }

    /**
     * Sets the master token. This is useful if you'd like to authenticate with the API without providing your
     * username and password. Do note that the master token has full access to your account.
     */
    public function setMasterToken(#[\SensitiveParameter] ?string $masterToken): void
    {
        $this->masterToken = $masterToken;
    }

    /** Gets the account email. */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /** Sets the account email. */
    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    /** Gets the device id. */
    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    /** Sets the device id. */
    public function setDeviceId(?string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }

    /** Gets the auth token. */
    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    /**
     * Refresh the OAuth token.
     *
     * @return string The auth token.
     * @throws LoginException If there was a problem refreshing the OAuth token.
     */
    public function refresh(): string
    {
        if ($this->email === null || $this->masterToken === null || $this->deviceId === null) {
            throw new LoginException('Email, master token and device id are required');
        }

        // Obtain an OAuth token with the necessary scopes by pretending to be the keep android client.
        $res = $this->gpsOAuth->performOAuth(
            $this->email,
            $this->masterToken,
            $this->deviceId,
            $this->scopes,
            'com.google.android.keep',
            GpsOAuth::DEFAULT_CLIENT_SIG,
        );

        // Bail if no token was returned.
        if (!array_key_exists('Auth', $res)) {
            throw new LoginException($this->errorMessage($res));
        }

        $this->authToken = $res['Auth'];

        return $this->authToken;
    }

    /** Log out of the account. */
    public function logout(): void
    {
        $this->masterToken = null;
        $this->authToken = null;
        $this->email = null;
        $this->deviceId = null;
    }

    /**
     * Build an exception message from an auth response without echoing tokens.
     *
     * @param array<string, string> $res
     */
    private function errorMessage(array $res): string
    {
        $message = $res['Error'] ?? 'Login failed';
        if (isset($res['ErrorDetail'])) {
            $message .= ': ' . $res['ErrorDetail'];
        }

        return $message;
    }
}
