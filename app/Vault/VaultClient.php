<?php

declare(strict_types=1);

namespace App\Vault;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the Vault HTTP API for reading KV (v1 and v2) secrets.
 *
 * The token is sent via the X-Vault-Token header. Secret values are NEVER
 * logged (DORA art. 9/11 - the audit trail must not contain sensitive data).
 */
final class VaultClient
{
    private string $address;
    private HttpClientInterface $http;

    public function __construct(
        string $address,
        private string $token,
        private ?string $namespace = null,
        private int $kvVersion = 2,
        ?HttpClientInterface $http = null,
    ) {
        $this->address = rtrim($address, '/');
        $this->http = $http ?? HttpClient::create(['timeout' => 10]);
    }

    /**
     * Reads a single KV secret and returns key => value pairs.
     *
     * @return array<string, mixed>
     *
     * @throws VaultException
     */
    public function readKv(string $mount, string $path): array
    {
        $mount = trim($mount, '/');
        $path = trim($path, '/');

        $url = $this->kvVersion === 2
            ? sprintf('%s/v1/%s/data/%s', $this->address, $mount, $path)
            : sprintf('%s/v1/%s/%s', $this->address, $mount, $path);

        $headers = ['X-Vault-Token' => $this->token];
        if ($this->namespace !== null && $this->namespace !== '') {
            $headers['X-Vault-Namespace'] = $this->namespace;
        }

        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false); // false => does not throw on 4xx/5xx
        } catch (TransportExceptionInterface $e) {
            throw new VaultException(
                sprintf('Vault is unreachable at %s: %s', $this->address, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($status === 403) {
            throw new VaultException(sprintf(
                'Vault denied access (403) to "%s/%s". Check VAULT_TOKEN and the policy.',
                $mount,
                $path,
            ));
        }

        if ($status === 404) {
            throw new VaultException(sprintf(
                'Secret not found (404) at "%s/%s" (KV v%d). Check the mount, path and KV version.',
                $mount,
                $path,
                $this->kvVersion,
            ));
        }

        /** @var array<string, mixed>|null $body */
        $body = json_decode($raw, true);

        if ($status >= 400) {
            $errors = is_array($body) && isset($body['errors']) && is_array($body['errors'])
                ? implode('; ', array_map(static fn (mixed $e): string => is_scalar($e) ? (string) $e : '', $body['errors']))
                : 'unknown error';
            throw new VaultException(sprintf('Vault returned HTTP %d for "%s/%s": %s', $status, $mount, $path, $errors));
        }

        if (!is_array($body)) {
            throw new VaultException(sprintf('Vault returned invalid JSON for "%s/%s".', $mount, $path));
        }

        if ($this->kvVersion === 2) {
            $outer = $body['data'] ?? null;
            $data = is_array($outer) ? ($outer['data'] ?? null) : null;
        } else {
            $data = $body['data'] ?? null;
        }

        if (!is_array($data)) {
            throw new VaultException(sprintf(
                'Unexpected response shape from Vault for "%s/%s" (missing data field).',
                $mount,
                $path,
            ));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
