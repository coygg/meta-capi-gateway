<?php

declare(strict_types=1);

namespace Gateway\Services;

use Gateway\Config;

final class CapiClient
{
    public function __construct(
        private readonly bool $dryRun,
        private readonly string $pixelId,
        private readonly string $accessToken,
        private readonly string $graphVersion,
        private readonly string $graphBaseUrl,
        private readonly string $testEventCode = '',
        private readonly string $transport = 'auto',
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            dryRun: $config->bool('capi_dry_run', true),
            pixelId: $config->string('meta_pixel_id'),
            accessToken: $config->string('meta_access_token'),
            graphVersion: $config->string('meta_graph_version', 'v20.0'),
            graphBaseUrl: $config->string('meta_graph_base_url', 'https://graph.facebook.com'),
            testEventCode: $config->string('meta_test_event_code'),
            transport: $config->string('meta_http_transport', 'auto'),
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array{dry_run: bool, status_code: int|null, response: array<string, mixed>, payload: array<string, mixed>}
     */
    public function send(array $event): array
    {
        $payload = ['data' => [$event]];

        if ($this->testEventCode !== '') {
            $payload['test_event_code'] = $this->testEventCode;
        }

        if ($this->dryRun) {
            return [
                'dry_run' => true,
                'status_code' => null,
                'response' => ['dry_run' => true],
                'payload' => $payload,
            ];
        }

        if ($this->pixelId === '' || $this->accessToken === '') {
            throw new \RuntimeException('META_PIXEL_ID and META_ACCESS_TOKEN are required when CAPI_DRY_RUN=false.');
        }

        $url = sprintf(
            '%s/%s/%s/events?access_token=%s',
            $this->graphBaseUrl,
            rawurlencode($this->graphVersion),
            rawurlencode($this->pixelId),
            rawurlencode($this->accessToken),
        );

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Unable to encode CAPI payload.');
        }

        if ($this->transport === 'curl' || ($this->transport === 'auto' && function_exists('curl_init'))) {
            return $this->sendWithCurl($url, $json, $payload);
        }

        if ($this->transport === 'stream' || $this->transport === 'auto') {
            return $this->sendWithStreams($url, $json, $payload);
        }

        throw new \RuntimeException('Unsupported CAPI HTTP transport: ' . $this->transport);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{dry_run: bool, status_code: int|null, response: array<string, mixed>, payload: array<string, mixed>}
     */
    private function sendWithCurl(string $url, string $json, array $payload): array
    {
        $curl = curl_init($url);

        // @coverage-ignore-start
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }
        // @coverage-ignore-end

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new \RuntimeException('CAPI request failed: ' . $error);
        }

        return [
            'dry_run' => false,
            'status_code' => is_int($status) ? $status : null,
            'response' => $this->decodeResponse((string) $body),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{dry_run: bool, status_code: int|null, response: array<string, mixed>, payload: array<string, mixed>}
     */
    private function sendWithStreams(string $url, string $json, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException('CAPI request failed.');
        }

        $status = null;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) === 1) {
            $status = (int) $match[1];
        }

        return [
            'dry_run' => false,
            'status_code' => $status,
            'response' => $this->decodeResponse($body),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
