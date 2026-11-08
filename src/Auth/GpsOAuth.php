<?php

declare(strict_types=1);

namespace Gkeepapi\Auth;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Gkeepapi\Exception\LoginException;

/**
 * Client for Google Play Services OAuth (the Android login emulation).
 *
 * Port of gpsoauth (MIT, (c) 2015 Simon Weber) https://github.com/simon-weber/gpsoauth @ 3.0.0.
 *
 * The HTTP client is injected (PSR-18). Google is picky about the TLS handshake: gpsoauth offers no ALPN and
 * allows TLS session tickets, and Google answers "403 Bad Authentication" otherwise. Configure the injected
 * client accordingly (HTTP/1.1, ALPN disabled); this cannot be done through PSR-18.
 */
final readonly class GpsOAuth
{
    public const AUTH_URL = 'https://android.clients.google.com/auth';
    public const USER_AGENT = 'GoogleAuth/1.4';
    public const DEFAULT_CLIENT_SIG = '38918a453d07199354f8b19af05ec6562ced5788';

    private GoogleKey $key;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        ?GoogleKey $key = null,
    ) {
        $this->key = $key ?? GoogleKey::android();
    }

    /**
     * Parse a received auth response (`key=value` lines).
     *
     * @return array<string, string>
     */
    public static function parseAuthResponse(string $text): array
    {
        $data = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode('=', $line, 2);
            $data[$parts[0]] = $parts[1] ?? '';
        }

        return $data;
    }

    /**
     * Construct the encrypted credentials signature.
     *
     * @throws LoginException If the encryption fails.
     */
    public static function constructSignature(
        string $email,
        #[\SensitiveParameter] string $password,
        GoogleKey $key,
    ): string {
        $signature = "\x00" . substr(sha1($key->toStruct(), true), 0, 4);
        $signature .= $key->encrypt($email . "\x00" . $password);

        return strtr(base64_encode($signature), '+/', '-_');
    }

    /**
     * Return the form fields {@see self::performMasterLogin()} posts to the auth URL.
     * `EncryptedPasswd` differs on each call, as the encryption is randomized.
     *
     * @return array<string, int|string>
     */
    public function buildMasterLoginData(
        string $email,
        #[\SensitiveParameter] string $password,
        string $androidId,
        string $service = 'ac2dm',
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
        string $clientSig = self::DEFAULT_CLIENT_SIG,
    ): array {
        return [
            'accountType' => 'HOSTED_OR_GOOGLE',
            'Email' => $email,
            'has_permission' => 1,
            'add_account' => 1,
            'EncryptedPasswd' => self::constructSignature($email, $password, $this->key),
            'service' => $service,
            'source' => 'android',
            'androidId' => $androidId,
            'device_country' => $deviceCountry,
            'operatorCountry' => $operatorCountry,
            'lang' => $lang,
            'sdk_version' => $sdkVersion,
            'google_play_services_version' => 240913000,
            'client_sig' => $clientSig,
            'callerSig' => $clientSig,
            'droidguard_results' => 'dummy123',
        ];
    }

    /**
     * Perform a master login, which is what Android does when you first add a Google account.
     *
     * @return array<string, string> The response fields, e.g. `Token`, `Auth`, `Error`.
     * @throws LoginException If the request cannot be sent.
     */
    public function performMasterLogin(
        string $email,
        #[\SensitiveParameter] string $password,
        string $androidId,
        string $service = 'ac2dm',
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
        string $clientSig = self::DEFAULT_CLIENT_SIG,
    ): array {
        return $this->performAuthRequest($this->buildMasterLoginData(
            $email,
            $password,
            $androidId,
            $service,
            $deviceCountry,
            $operatorCountry,
            $lang,
            $sdkVersion,
            $clientSig,
        ));
    }

    /**
     * Return the form fields {@see self::exchangeToken()} posts to the auth URL.
     *
     * @return array<string, int|string>
     */
    public function buildExchangeTokenData(
        string $email,
        #[\SensitiveParameter] string $token,
        string $androidId,
        string $service = 'ac2dm',
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
        string $clientSig = self::DEFAULT_CLIENT_SIG,
    ): array {
        return [
            'accountType' => 'HOSTED_OR_GOOGLE',
            'Email' => $email,
            'has_permission' => 1,
            'add_account' => 1,
            'ACCESS_TOKEN' => 1,
            'Token' => $token,
            'service' => $service,
            'source' => 'android',
            'androidId' => $androidId,
            'device_country' => $deviceCountry,
            'operatorCountry' => $operatorCountry,
            'lang' => $lang,
            'sdk_version' => $sdkVersion,
            'google_play_services_version' => 240913000,
            'client_sig' => $clientSig,
            'callerSig' => $clientSig,
            'droidguard_results' => 'dummy123',
        ];
    }

    /**
     * Exchange a web oauth_token for a master token.
     *
     * @return array<string, string> The response fields.
     * @throws LoginException If the request cannot be sent.
     */
    public function exchangeToken(
        string $email,
        #[\SensitiveParameter] string $token,
        string $androidId,
        string $service = 'ac2dm',
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
        string $clientSig = self::DEFAULT_CLIENT_SIG,
    ): array {
        return $this->performAuthRequest($this->buildExchangeTokenData(
            $email,
            $token,
            $androidId,
            $service,
            $deviceCountry,
            $operatorCountry,
            $lang,
            $sdkVersion,
            $clientSig,
        ));
    }

    /**
     * Return the form fields {@see self::performOAuth()} posts to the auth URL.
     *
     * @return array<string, int|string>
     */
    public function buildOAuthData(
        string $email,
        #[\SensitiveParameter] string $masterToken,
        string $androidId,
        string $service,
        string $app,
        string $clientSig,
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
    ): array {
        return [
            'accountType' => 'HOSTED_OR_GOOGLE',
            'Email' => $email,
            'has_permission' => 1,
            'EncryptedPasswd' => $masterToken,
            'service' => $service,
            'source' => 'android',
            'androidId' => $androidId,
            'app' => $app,
            'client_sig' => $clientSig,
            'device_country' => $deviceCountry,
            'operatorCountry' => $operatorCountry,
            'lang' => $lang,
            'sdk_version' => $sdkVersion,
            'google_play_services_version' => 240913000,
        ];
    }

    /**
     * Use a master token to perform OAuth to a specific Google service.
     *
     * To authenticate requests to the service, send `Authorization: GoogleLogin auth=<Auth>`.
     *
     * @return array<string, string> The response fields, e.g. `Auth`, `Error`.
     * @throws LoginException If the request cannot be sent.
     */
    public function performOAuth(
        string $email,
        #[\SensitiveParameter] string $masterToken,
        string $androidId,
        string $service,
        string $app,
        string $clientSig,
        string $deviceCountry = 'us',
        string $operatorCountry = 'us',
        string $lang = 'en',
        int $sdkVersion = 17,
    ): array {
        return $this->performAuthRequest($this->buildOAuthData(
            $email,
            $masterToken,
            $androidId,
            $service,
            $app,
            $clientSig,
            $deviceCountry,
            $operatorCountry,
            $lang,
            $sdkVersion,
        ));
    }

    /**
     * @param array<string, int|string> $data
     * @return array<string, string>
     * @throws LoginException If the request cannot be sent.
     */
    private function performAuthRequest(#[\SensitiveParameter] array $data): array
    {
        $request = $this->requestFactory->createRequest('POST', self::AUTH_URL)
            ->withHeader('Accept-Encoding', 'identity')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withBody($this->streamFactory->createStream(http_build_query($data)));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The message may echo the request; do not include it.
            throw new LoginException('Auth request failed', 0, $e);
        }

        return self::parseAuthResponse((string) $response->getBody());
    }
}
