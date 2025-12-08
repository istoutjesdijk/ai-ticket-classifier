<?php
/**
 * AI Ticket Classifier - API Client
 *
 * Handles API calls to OpenAI and Anthropic for ticket classification.
 */

require_once(INCLUDE_DIR . 'class.format.php');
require_once(INCLUDE_DIR . 'class.json.php');

class AIClassifierClient {

    const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

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

    /**
     * @param string $provider 'openai' or 'anthropic'
     * @param string $apiKey API key
     * @param string $model Model name
     * @param int $timeout Timeout in seconds
     * @param float $temperature Temperature (0-2)
     */
    public function __construct($provider, $apiKey, $model, $timeout = 30, $temperature = 1.0) {
        $this->provider = strtolower($provider);
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = (int) $timeout;
        $this->temperature = (float) $temperature;
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
        $prompt = $this->buildPrompt($content, $topics, $priorities, $customFields);

        $response = ($this->provider === 'anthropic')
            ? $this->callAnthropic($prompt)
            : $this->callOpenAI($prompt);

        return $this->parseResponse($response, $topics, $priorities, $customFields);
    }

    /**
     * Build classification prompt
     */
    private function buildPrompt($content, $topics, $priorities, $customFields) {
        $prompt = "You are a support ticket classifier. Analyze the following ticket and classify it.\n\n";
        $prompt .= "TICKET CONTENT:\n---\n{$content}\n---\n\n";

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
                if ($field['type'] === 'choices' && !empty($field['choices'])) {
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
     * Call OpenAI API
     */
    private function callOpenAI($prompt) {
        $payload = array(
            'model' => $this->model,
            'messages' => array(
                array('role' => 'system', 'content' => 'You are a ticket classification assistant. Always respond with valid JSON only.'),
                array('role' => 'user', 'content' => $prompt)
            ),
        );

        // Add temperature for models that support it
        if (!$this->isModelWithoutTemperature()) {
            $payload['temperature'] = $this->temperature;
        }

        // Use correct token parameter based on model
        if ($this->isLegacyModel()) {
            $payload['max_tokens'] = 500;
        } else {
            $payload['max_completion_tokens'] = 500;
        }

        $response = $this->request(self::OPENAI_URL, $payload, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ));

        $json = $this->decodeJson($response, 'OpenAI');

        if (isset($json['error'])) {
            throw new Exception('OpenAI API error: ' . ($json['error']['message'] ?? 'Unknown'));
        }

        if (!isset($json['choices'][0]['message']['content'])) {
            throw new Exception('Unexpected OpenAI response format');
        }

        return $json['choices'][0]['message']['content'];
    }

    /**
     * Call Anthropic API
     */
    private function callAnthropic($prompt) {
        $payload = array(
            'model' => $this->model,
            'max_tokens' => 500,
            'system' => 'You are a ticket classification assistant. Always respond with valid JSON only.',
            'messages' => array(
                array('role' => 'user', 'content' => $prompt)
            ),
        );

        $response = $this->request(self::ANTHROPIC_URL, $payload, array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
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
     * Check if model is legacy (uses max_tokens)
     */
    private function isLegacyModel() {
        // gpt-3.5-* and base gpt-4 (not gpt-4o, gpt-4-turbo)
        return preg_match('/^gpt-(3\.5|4$)/i', $this->model);
    }

    /**
     * Check if model doesn't support temperature
     */
    private function isModelWithoutTemperature() {
        // gpt-5 and o-series models don't support custom temperature
        return preg_match('/^(gpt-5|o1|o3)/i', $this->model);
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
                    // Match by value or key
                    $key = array_search($value, $field['choices']);
                    if ($key !== false) {
                        $customFieldValues[$name] = $key;
                    } elseif (isset($field['choices'][$value])) {
                        $customFieldValues[$name] = $value;
                    }
                } else {
                    $customFieldValues[$name] = (string) $value;
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
