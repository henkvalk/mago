<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Model\Ai\Provider;

use MaggyAssistant\Base\Api\Ai\ProviderInterface;
use MaggyAssistant\Base\Api\Config\RepositoryInterface as ConfigRepository;
use MaggyAssistant\Base\Model\Ai\Claude\SupportedModel;
use MaggyAssistant\Base\Service\Ai\RestClient;

class Claude implements ProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly RestClient $restClient
    ) {
    }

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $body = $this->buildRequestBody($messages, $tools, $options);
        $headers = $this->getHeaders();

        $response = $this->restClient->execute(
            self::API_URL,
            $headers,
            $body,
            $this->configRepository->isDebugEnabled()
        );

        if (!empty($response['error'])) {
            return [
                'content' => 'Error: ' . ($response['message'] ?? 'Unknown error'),
                'tool_calls' => [],
            ];
        }

        $parsed = $this->parseResponse($response);
        $parsed['usage'] = [
            'input_tokens' => $response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $response['usage']['output_tokens'] ?? 0,
        ];
        return $parsed;
    }

    public function stream(array $messages, array $tools = [], array $options = [], ?callable $onChunk = null): array
    {
        $body = $this->buildRequestBody($messages, $tools, $options);
        $body['stream'] = true;
        $headers = $this->getHeaders();

        $fullContent = '';
        $toolCalls = [];
        $currentToolCall = null;
        $buffer = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        $this->restClient->stream(
            self::API_URL,
            $headers,
            $body,
            function (string $chunk) use (&$fullContent, &$toolCalls, &$currentToolCall, &$buffer, &$usage, $onChunk) {
                $buffer .= $chunk;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        continue;
                    }

                    try {
                        $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        continue;
                    }

                    $type = $event['type'] ?? '';

                    if ($type === 'content_block_start') {
                        $block = $event['content_block'] ?? [];
                        if (($block['type'] ?? '') === 'tool_use') {
                            $currentToolCall = [
                                'id' => $block['id'] ?? '',
                                'name' => $block['name'] ?? '',
                                'input_json' => '',
                            ];
                        }
                    } elseif ($type === 'content_block_delta') {
                        $delta = $event['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            $text = $delta['text'] ?? '';
                            $fullContent .= $text;
                            if ($onChunk) {
                                $onChunk('text', ['text' => $text]);
                            }
                        } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                            if ($currentToolCall !== null) {
                                $currentToolCall['input_json'] .= ($delta['partial_json'] ?? '');
                            }
                        }
                    } elseif ($type === 'content_block_stop') {
                        if ($currentToolCall !== null) {
                            $input = [];
                            if (!empty($currentToolCall['input_json'])) {
                                try {
                                    $input = json_decode(
                                        $currentToolCall['input_json'],
                                        true,
                                        512,
                                        JSON_THROW_ON_ERROR
                                    );
                                } catch (\Throwable $e) {
                                    $input = [];
                                }
                            }
                            $toolCalls[] = [
                                'id' => $currentToolCall['id'],
                                'name' => $currentToolCall['name'],
                                'input' => $input,
                            ];
                            if ($onChunk) {
                                $onChunk('tool_call', [
                                    'id' => $currentToolCall['id'],
                                    'name' => $currentToolCall['name'],
                                    'input' => $input,
                                ]);
                            }
                            $currentToolCall = null;
                        }
                    } elseif ($type === 'message_start') {
                        $msgUsage = $event['message']['usage'] ?? [];
                        $usage['input_tokens'] = $msgUsage['input_tokens'] ?? 0;
                    } elseif ($type === 'message_delta') {
                        $deltaUsage = $event['usage'] ?? [];
                        $usage['output_tokens'] = $deltaUsage['output_tokens'] ?? 0;
                    }
                }
            },
            $this->configRepository->isDebugEnabled()
        );

        return [
            'content' => $fullContent,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
        ];
    }

    public function getProviderName(): string
    {
        return 'claude';
    }

    private function getHeaders(): array
    {
        return [
            'x-api-key' => $this->configRepository->getApiKey(),
            'anthropic-version' => self::API_VERSION,
        ];
    }

    private function buildRequestBody(array $messages, array $tools, array $options): array
    {
        $systemMessages = array_filter($messages, fn($m) => ($m['role'] ?? '') === 'system');
        $nonSystemMessages = array_values(array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'system'));

        $model = $options['model'] ?? $this->configRepository->getModel();

        $body = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? $this->configRepository->getMaxTokens(),
            'messages' => $this->formatMessages($nonSystemMessages),
        ];

        $temperature = $options['temperature'] ?? $this->configRepository->getTemperature();
        if ($temperature > 0 && SupportedModel::canModelUseTemperature($model)) {
            $body['temperature'] = $temperature;
        }

        if (!empty($systemMessages)) {
            $body['system'] = implode("\n\n", array_map(fn($m) => $m['content'], $systemMessages));
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        return $body;
    }

    private function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            if ($role === 'tool') {
                $formatted[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $message['tool_call_id'] ?? '',
                            'content' => $message['content'] ?? '',
                        ],
                    ],
                ];
            } elseif ($role === 'assistant' && !empty($message['tool_calls'])) {
                $content = [];
                if (!empty($message['content'])) {
                    $content[] = ['type' => 'text', 'text' => $message['content']];
                }
                foreach ($message['tool_calls'] as $tc) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['name'],
                        'input' => $tc['input'] ?? [],
                    ];
                }
                $formatted[] = ['role' => 'assistant', 'content' => $content];
            } else {
                $formatted[] = [
                    'role' => $role === 'assistant' ? 'assistant' : 'user',
                    'content' => $message['content'] ?? '',
                ];
            }
        }
        return $formatted;
    }

    private function formatTools(array $tools): array
    {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $formatted;
    }

    private function parseResponse(array $response): array
    {
        $content = '';
        $toolCalls = [];

        foreach (($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];
    }
}
