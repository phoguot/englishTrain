<?php

declare(strict_types=1);

namespace User\OAuth;

use Laminas\Http\Client;
use RuntimeException;

abstract class AbstractOAuthProvider implements OAuthProviderInterface
{
    public function __construct(
        protected readonly Client $httpClient,
        protected readonly string $clientId,
        protected readonly string $clientSecret,
    ) {
    }

    /** @param array<string,string> $params @param array<string,string> $headers @return array<string,mixed> */
    protected function postForm(string $uri, array $params, array $headers = []): array
    {
        $client = clone $this->httpClient;
        $client->setUri($uri);
        $client->setMethod('POST');
        $client->setParameterPost($params);
        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        return $this->decodeResponse($client->send());
    }

    /** @param array<string,string> $params @param array<string,string> $headers @return array<string,mixed> */
    protected function getJson(string $uri, array $params = [], array $headers = []): array
    {
        $client = clone $this->httpClient;
        $client->setUri($uri);
        $client->setMethod('GET');
        if ($params !== []) {
            $client->setParameterGet($params);
        }
        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        return $this->decodeResponse($client->send());
    }

    /** @return array<string,mixed> */
    private function decodeResponse(\Laminas\Http\Response $response): array
    {
        if (!$response->isSuccess()) {
            throw new RuntimeException('Nhà cung cấp đăng nhập trả về trạng thái không thành công.');
        }
        $data = json_decode($response->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('Nhà cung cấp đăng nhập trả về dữ liệu không hợp lệ.');
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException('Nhà cung cấp đăng nhập không trả về đủ thông tin danh tính.');
        }

        return (string) $value;
    }
}
