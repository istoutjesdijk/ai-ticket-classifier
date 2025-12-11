<?php
/**
 * AI Ticket Classifier - API Client
 *
 * Handles API calls to OpenAI and Anthropic for ticket classification.
 */

require_once(INCLUDE_DIR . 'class.format.php');
require_once(INCLUDE_DIR . 'class.json.php');
require_once(__DIR__ . '/AIConfig.php');

class AIClassifierClient {

    /** @var string */
    private $provider;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var int */
    private $timeout;

    /** @var float */
    private $temperature;

    /** @var int */
    private $maxTokens;

    /** @var bool */
    private $storeResponses;

    /**
     * @param string $provider 'openai' or 'anthropic'
     * @param string $apiKey API key
     * @param string $model Model name
     * @param int $timeout Timeout in seconds
     * @param float $temperature Temperature (0-2)
     * @param int $maxTokens Maximum response tokens
     * @param bool $storeResponses Store responses in OpenAI dashboard
     */
    public function __construct($provider, $apiKey, $model, $timeout = null, $temperature = null, $maxTokens = null, $storeResponses = null) {
        $this->provider = strtolower($provider);
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = (int) ($timeout ?? AIConfig::DEFAULT_TIMEOUT);
        $this->temperature = (float) ($temperature ?? AIConfig::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int) ($maxTokens ?? AIConfig::DEFAULT_MAX_TOKENS);
        $this->storeResponses = (bool) ($storeResponses ?? AIConfig::DEFAULT_STORE_RESPONSES);
    }

    /**
     * Classify ticket content
     *
     * @param string $content Ticket content
     * @param array $topics Available topics [id => name]
     * @param array $priorities Available priorities [id => name]
     * @param array $customFields Custom field definitions
     * @return array Classification result
     * @throws Exception On API error
     */
    public function classify($content, $topics, $priorities, $customFields = array()) {
        $systemPrompt = $this->buildSystemPrompt($topics, $priorities, $customFields);
        $userMessage = $this->buildUserMessage($content);

        $response = ($this->provider === 'anthropic')
            ? $this->callAnthropic($systemPrompt, $userMessage)
            : $this->callOpenAI($systemPrompt, $userMessage);

        return $this->parseResponse($response, $topics, $priorities, $customFields);
    }

    /**
     * Build system prompt with classification instructions
     */
    private function buildSystemPrompt($topics, $priorities, $customFields) {
        $prompt = "You are a support ticket classifier. Analyze tickets and classify them.\n\n";

        // Topics
        $prompt .= "AVAILABLE TOPICS (choose one topic_id):\n";
        foreach ($topics as $id => $name) {
            $prompt .= "- ID: {$id}, Name: {$name}\n";
        }
        $prompt .= "\n";

        // Priorities
        $prompt .= "AVAILABLE PRIORITIES (choose one priority_id):\n";
        foreach ($priorities as $id => $name) {
            $prompt .= "- ID: {$id}, Name: {$name}\n";
        }
        $prompt .= "\n";

        // Custom fields
        if (!empty($customFields)) {
            $prompt .= "CUSTOM FIELDS TO FILL:\n";
            foreach ($customFields as $name => $field) {
                $prompt .= "- Field: {$name} (Label: {$field['label']}, Type: {$field['type']})";
                if (!empty($field['max_length'])) {
                    $prompt .= " - Max {$field['max_length']} characters";
                }
                if (!empty($field['validator'])) {
                    $prompt .= " - Must be valid {$field['validator']}";
                }
                if ($field['type'] === 'choices' && !empty($field['choices'])) {
                    if (!empty($field['multiselect'])) {
                        $prompt .= " - Multiple selections allowed, return as array";
                    }
                    $prompt .= " - Choices: " . implode(', ', array_values($field['choices']));
                } elseif ($field['type'] === 'bool') {
                    $prompt .= " - Use true or false";
                }
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }

        // Response format
        $prompt .= "RESPOND WITH VALID JSON ONLY (no markdown, no explanation):\n";
        $prompt .= "{\n  \"topic_id\": <number>,\n  \"priority_id\": <number>";

        if (!empty($customFields)) {
            $prompt .= ",\n  \"custom_fields\": {\n";
            $lines = array();
            foreach ($customFields as $name => $field) {
                $placeholder = ($field['type'] === 'bool') ? '<true|false>' : '"<value>"';
                $lines[] = "    \"{$name}\": {$placeholder}";
            }
            $prompt .= implode(",\n", $lines) . "\n  }";
        }

        $prompt .= "\n}";

        return $prompt;
    }

    /**
     * Build user message with ticket content
     */
    private function buildUserMessage($content) {
        return "Classify this ticket:\n\n{$content}";
    }

    /**
     * Call OpenAI Responses API
     */
    private function callOpenAI($systemPrompt, $userMessage) {
        $payload = array(
            'model' => $this->model,
            'instructions' => $systemPrompt,
            'input' => $userMessage,
            'max_output_tokens' => $this->maxTokens,
            'store' => $this->storeResponses,
        );

        // Add temperature for models that support it
        if (!$this->isModelWithoutTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        $response = $this->request(AIConfig::OPENAI_URL, $payload, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ));

        $json = $this->decodeJson($response, 'OpenAI');

        if (isset($json['error'])) {
            throw new Exception('OpenAI API error: ' . ($json['error']['message'] ?? 'Unknown'));
        }

        if ($json['status'] === 'incomplete') {
            $reason = $json['incomplete_details']['reason'] ?? 'unknown';
            throw new Exception("OpenAI response incomplete: {$reason}. Try increasing max_output_tokens.");
        }

        if ($json['status'] !== 'completed') {
            throw new Exception('OpenAI response status: ' . ($json['status'] ?? 'unknown'));
        }

        // Extract text from message output
        if (!isset($json['output']) || !is_array($json['output'])) {
            throw new Exception('OpenAI response missing output array');
        }

        // Find the message in output
        foreach ($json['output'] as $item) {
            if (isset($item['type']) && $item['type'] === 'message' && isset($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (isset($content['type']) && $content['type'] === 'output_text' && isset($content['text'])) {
                        return $content['text'];
                    }
                }
            }
        }

        throw new Exception('No text content found in OpenAI response');
    }

    /**
     * Call Anthropic API
     */
    private function callAnthropic($systemPrompt, $userMessage) {
        $payload = array(
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => array(
                array('role' => 'user', 'content' => $userMessage)
            ),
        );

        $response = $this->request(AIConfig::ANTHROPIC_URL, $payload, array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . AIConfig::ANTHROPIC_VERSION,
        ));

        $json = $this->decodeJson($response, 'Anthropic');

        if (isset($json['error'])) {
            throw new Exception('Anthropic API error: ' . ($json['error']['message'] ?? 'Unknown'));
        }

        if (!isset($json['content'][0]['text'])) {
            throw new Exception('Unexpected Anthropic response format');
        }

        return $json['content'][0]['text'];
    }

    /**
     * Check if model doesn't support temperature parameter (o-series reasoning models)
     */
    private function isModelWithoutTemperature() {
        return preg_match(AIConfig::NO_TEMPERATURE_PATTERN, $this->model);
    }

    /**
     * Execute HTTP request
     */
    private function request($url, $payload, $headers) {
        $json = Format::json_encode($payload);
        if (!$json) {
            throw new Exception('Failed to encode request payload');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            throw new Exception("cURL error ({$errno}): {$error}");
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $msg = $body['error']['message'] ?? $response;
            throw new Exception("API error (HTTP {$httpCode}): {$msg}");
        }

        return $response;
    }

    /**
     * Decode JSON response
     */
    private function decodeJson($response, $provider) {
        $json = JsonDataParser::decode($response);
        if ($json === null && $response) {
            throw new Exception("Invalid JSON from {$provider}: " . JsonDataParser::lastError());
        }
        return $json;
    }

    /**
     * Parse AI response
     */
    private function parseResponse($response, $topics, $priorities, $customFields) {
        // Clean markdown if present
        $response = trim($response);
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        $result = JsonDataParser::decode($response);
        if ($result === null) {
            throw new Exception('Failed to parse AI response: ' . JsonDataParser::lastError());
        }

        // Validate topic
        $topicId = null;
        if (isset($result['topic_id'])) {
            $id = (int) $result['topic_id'];
            $topicId = isset($topics[$id]) ? $id : null;
        }

        // Validate priority
        $priorityId = null;
        if (isset($result['priority_id'])) {
            $id = (int) $result['priority_id'];
            $priorityId = isset($priorities[$id]) ? $id : null;
        }

        // Parse custom fields
        $customFieldValues = array();
        if (isset($result['custom_fields']) && is_array($result['custom_fields'])) {
            foreach ($result['custom_fields'] as $name => $value) {
                if (!isset($customFields[$name])) {
                    continue;
                }

                $field = $customFields[$name];
                $type = $field['type'];

                if ($type === 'bool') {
                    $customFieldValues[$name] = $value ? 1 : 0;
                } elseif ($type === 'choices' && isset($field['choices'])) {
                    if (!empty($field['multiselect']) && is_array($value)) {
                        // Multiselect: map array of values to keys
                        $keys = array();
                        foreach ($value as $v) {
                            $key = array_search($v, $field['choices']);
                            if ($key !== false) {
                                $keys[$key] = $field['choices'][$key];
                            } elseif (isset($field['choices'][$v])) {
                                $keys[$v] = $field['choices'][$v];
                            }
                        }
                        $customFieldValues[$name] = $keys;
                    } else {
                        // Single select: match by value or key
                        $key = array_search($value, $field['choices']);
                        if ($key !== false) {
                            $customFieldValues[$name] = $key;
                        } elseif (isset($field['choices'][$value])) {
                            $customFieldValues[$name] = $value;
                        }
                    }
                } else {
                    $strValue = (string) $value;
                    // Enforce max length if configured
                    if (!empty($field['max_length']) && mb_strlen($strValue) > $field['max_length']) {
                        $strValue = mb_substr($strValue, 0, $field['max_length']);
                    }
                    $customFieldValues[$name] = $strValue;
                }
            }
        }

        return array(
            'topic_id' => $topicId,
            'priority_id' => $priorityId,
            'custom_fields' => $customFieldValues,
        );
    }
}
