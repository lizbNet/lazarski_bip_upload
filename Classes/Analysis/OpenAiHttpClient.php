<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Real OpenAI Chat Completions caller, using TYPO3's own outbound HTTP client
 * (TYPO3\CMS\Core\Http\RequestFactory, wrapping Guzzle - already a core dependency,
 * no new HTTP library needed). Any failure (network error, timeout, non-200, malformed
 * JSON) is swallowed and returns null - callers must treat this as "unavailable" and fall
 * back to the deterministic heuristic, never as a hard error.
 *
 * All request/response detail (the actual reason a call was swallowed) is only visible via
 * the PSR-3 logger at debug/warning level - silent by default, since TYPO3 only writes debug
 * output for channels explicitly configured in $TYPO3_CONF_VARS['LOG']. Never logs the API key.
 */
class OpenAiHttpClient implements OpenAiClientInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const TIMEOUT_SECONDS = 15;
    private const LOG_BODY_PREVIEW_LENGTH = 2000;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    public function chatCompletionJson(string $apiKey, string $model, array $messages, float $temperature): ?array
    {
        $this->logger?->debug('OpenAI request', [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => $messages,
        ]);

        try {
            $response = $this->requestFactory->request(
                self::API_URL,
                'POST',
                [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'messages' => $messages,
                        'temperature' => $temperature,
                        'response_format' => ['type' => 'json_object'],
                    ],
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger?->warning('OpenAI request failed with an exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $statusCode = $response->getStatusCode();
        $rawBody = (string)$response->getBody();

        if ($statusCode !== 200) {
            $this->logger?->warning('OpenAI request returned a non-200 status', [
                'statusCode' => $statusCode,
                'body' => mb_substr($rawBody, 0, self::LOG_BODY_PREVIEW_LENGTH),
            ]);

            return null;
        }

        $body = json_decode($rawBody, true);
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            $this->logger?->warning('OpenAI response had no usable message content', [
                'body' => mb_substr($rawBody, 0, self::LOG_BODY_PREVIEW_LENGTH),
            ]);

            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $this->logger?->warning('OpenAI message content was not valid JSON', [
                'content' => mb_substr($content, 0, self::LOG_BODY_PREVIEW_LENGTH),
            ]);

            return null;
        }

        $this->logger?->debug('OpenAI response', ['decoded' => $decoded]);

        return $decoded;
    }
}
