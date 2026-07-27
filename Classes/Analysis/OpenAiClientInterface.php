<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Seam between OpenAiTitleGenerator and the actual HTTP call, so tests can exercise
 * success/timeout/malformed-response/non-200 scenarios without a real network call or API key.
 */
interface OpenAiClientInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>|null the decoded JSON object from the model's reply content,
     *         or null if the call failed, timed out, or the reply was not valid JSON
     */
    public function chatCompletionJson(string $apiKey, string $model, array $messages, float $temperature): ?array;
}
