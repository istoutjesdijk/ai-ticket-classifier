<?php
/**
 * AI Ticket Classifier - API Client
 *
 * Handles API calls to OpenAI and Anthropic for ticket classification.
 * Returns structured JSON responses for reliable parsing.
 */

class AIClassifierClient {

    private $provider;
    private $apiKey;
    private $model;
    private $timeout;

    // API endpoints
    const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Constructor
     *
     * @param string $provider Provider: 'openai' or 'anthropic'
     * @param string $apiKey API key for authentication
     * @param string $model Model name to use
     * @param int $timeout Request timeout in seconds
     */
    function __construct($provider, $apiKey, $model, $timeout = 30) {
        $this->provider = strtolower($provider);
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = (int) $timeout;
    }

    /**
     * Classify a ticket using AI
     *
     * @param string $ticketContent The ticket message content
     * @param array $topics Available topics [id => name]
     * @param array $priorities Available priorities [id => name]
     * @param array $customFields Custom field definitions [name => [type, label, choices?]]
     * @return array Classification result with topic_id, priority_id, custom_fields
     * @throws Exception On API error
     */
    function classify($ticketContent, $topics, $priorities, $customFields = array()) {
        // Build the classification prompt
        $prompt = $this->buildPrompt($ticketContent, $topics, $priorities, $customFields);

        // Call the appropriate API
        if ($this->provider === 'anthropic') {
            $response = $this->callAnthropic($prompt);
        } else {
            $response = $this->callOpenAI($prompt);
        }

        // Parse the JSON response
        return $this->parseResponse($response, $topics, $priorities, $customFields);
    }

    /**
     * Build the classification prompt
     *
     * @param string $ticketContent Ticket content
     * @param array $topics Available topics
     * @param array $priorities Available priorities
     * @param array $customFields Custom fields
     * @return string The prompt
     */
    private function buildPrompt($ticketContent, $topics, $priorities, $customFields) {
        $prompt = "You are a support ticket classifier. Analyze the following ticket and classify it.\n\n";

        // Add ticket content
        $prompt .= "TICKET CONTENT:\n";
        $prompt .= "---\n";
        $prompt .= $ticketContent . "\n";
        $prompt .= "---\n\n";

        // Add available topics
        $prompt .= "AVAILABLE TOPICS (choose one topic_id):\n";
        foreach ($topics as $id => $name) {
            $prompt .= "- ID: {$id}, Name: {$name}\n";
        }
        $prompt .= "\n";

        // Add available priorities
        $prompt .= "AVAILABLE PRIORITIES (choose one priority_id):\n";
        foreach ($priorities as $id => $name) {
            $prompt .= "- ID: {$id}, Name: {$name}\n";
        }
        $prompt .= "\n";

        // Add custom fields if any
        if (!empty($customFields)) {
            $prompt .= "CUSTOM FIELDS TO FILL:\n";
            foreach ($customFields as $name => $field) {
                $type = $field['type'];
                $label = $field['label'];
                $prompt .= "- Field: {$name} (Label: {$label}, Type: {$type})";

                if ($type === 'choices' && !empty($field['choices'])) {
                    $prompt .= " - Available choices: " . implode(', ', array_values($field['choices']));
                } elseif ($type === 'bool') {
                    $prompt .= " - Use true or false";
                }
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }

        // Request JSON response
        $prompt .= "RESPOND WITH VALID JSON ONLY (no markdown, no explanation):\n";
        $prompt .= "{\n";
        $prompt .= '  "topic_id": <number>,';
        $prompt .= "\n";
        $prompt .= '  "priority_id": <number>';

        if (!empty($customFields)) {
            $prompt .= ",\n";
            $prompt .= '  "custom_fields": {';
            $prompt .= "\n";
            $fieldLines = array();
            foreach ($customFields as $name => $field) {
                $type = $field['type'];
                if ($type === 'bool') {
                    $fieldLines[] = '    "' . $name . '": <true|false>';
                } elseif ($type === 'choices') {
                    $fieldLines[] = '    "' . $name . '": "<choice value>"';
                } else {
                    $fieldLines[] = '    "' . $name . '": "<text value>"';
                }
            }
            $prompt .= implode(",\n", $fieldLines);
            $prompt .= "\n  }";
        }

        $prompt .= "\n}";

        return $prompt;
    }

    /**
     * Call OpenAI API
     *
     * @param string $prompt The classification prompt
     * @return string Raw API response text
     * @throws Exception On API error
     */
    private function callOpenAI($prompt) {
        $payload = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => 'You are a ticket classification assistant. Always respond with valid JSON only, no markdown or explanation.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
        );

        // Temperature is not supported by gpt-5 and o-series models
        $noTemperatureModels = preg_match('/^(gpt-5|o1|o3)/i', $this->model);
        if (!$noTemperatureModels) {
            $payload['temperature'] = 0.1; // Low temperature for consistent classification
        }

        // Determine which token parameter to use
        // Legacy models (gpt-3.5, base gpt-4) use max_tokens
        // All newer models use max_completion_tokens
        $isLegacyModel = preg_match('/^gpt-(3\.5|4)(?!-|o)/i', $this->model)
                      || preg_match('/^gpt-4$/i', $this->model);

        if ($isLegacyModel) {
            $payload['max_tokens'] = 500;
        } else {
            // gpt-4o, gpt-4-turbo, gpt-5, o1, o3, etc.
            $payload['max_completion_tokens'] = 500;
        }

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        );

        $response = $this->curlRequest(self::OPENAI_URL, $payload, $headers);

        // Parse OpenAI response format
        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from OpenAI: ' . json_last_error_msg());
        }

        if (isset($json['error'])) {
            throw new Exception('OpenAI API error: ' . ($json['error']['message'] ?? 'Unknown error'));
        }

        if (!isset($json['choices'][0]['message']['content'])) {
            throw new Exception('Unexpected OpenAI response format');
        }

        return $json['choices'][0]['message']['content'];
    }

    /**
     * Call Anthropic API
     *
     * @param string $prompt The classification prompt
     * @return string Raw API response text
     * @throws Exception On API error
     */
    private function callAnthropic($prompt) {
        $payload = array(
            'model' => $this->model,
            'max_tokens' => 500,
            'system' => 'You are a ticket classification assistant. Always respond with valid JSON only, no markdown or explanation.',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
        );

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        );

        $response = $this->curlRequest(self::ANTHROPIC_URL, $payload, $headers);

        // Parse Anthropic response format
        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Anthropic: ' . json_last_error_msg());
        }

        if (isset($json['error'])) {
            throw new Exception('Anthropic API error: ' . ($json['error']['message'] ?? 'Unknown error'));
        }

        // Anthropic returns content as array of blocks
        if (!isset($json['content'][0]['text'])) {
            throw new Exception('Unexpected Anthropic response format');
        }

        return $json['content'][0]['text'];
    }

    /**
     * Execute cURL request
     *
     * @param string $url API URL
     * @param array $payload Request payload
     * @param array $headers HTTP headers
     * @return string Response body
     * @throws Exception On cURL error
     */
    private function curlRequest($url, $payload, $headers) {
        $ch = curl_init($url);

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new Exception('Failed to encode payload as JSON: ' . json_last_error_msg());
        }

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlErrno !== 0) {
            throw new Exception("cURL error ({$curlErrno}): {$curlError}");
        }

        if ($httpCode === 0) {
            throw new Exception('No HTTP response received. Check network connectivity.');
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMsg = $errorBody['error']['message'] ?? $response;
            throw new Exception("API error (HTTP {$httpCode}): " . $errorMsg);
        }

        return $response;
    }

    /**
     * Parse the AI response into classification result
     *
     * @param string $response Raw AI response
     * @param array $topics Available topics for validation
     * @param array $priorities Available priorities for validation
     * @param array $customFields Custom fields for validation
     * @return array Parsed classification result
     * @throws Exception On parse error
     */
    private function parseResponse($response, $topics, $priorities, $customFields) {
        // Clean up response - remove markdown code blocks if present
        $response = trim($response);
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        // Parse JSON
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse AI response as JSON: ' . json_last_error_msg() . '. Response: ' . substr($response, 0, 200));
        }

        // Validate and extract topic_id
        $topicId = null;
        if (isset($result['topic_id'])) {
            $topicId = (int) $result['topic_id'];
            // Verify it's a valid topic
            if (!isset($topics[$topicId])) {
                $topicId = null;
            }
        }

        // Validate and extract priority_id
        $priorityId = null;
        if (isset($result['priority_id'])) {
            $priorityId = (int) $result['priority_id'];
            // Verify it's a valid priority
            if (!isset($priorities[$priorityId])) {
                $priorityId = null;
            }
        }

        // Extract custom fields
        $customFieldValues = array();
        if (isset($result['custom_fields']) && is_array($result['custom_fields'])) {
            foreach ($result['custom_fields'] as $name => $value) {
                // Only include fields that were requested
                if (isset($customFields[$name])) {
                    $field = $customFields[$name];
                    $type = $field['type'];

                    // Validate value based on type
                    if ($type === 'bool') {
                        $customFieldValues[$name] = $value ? 1 : 0;
                    } elseif ($type === 'choices' && isset($field['choices'])) {
                        // For choice fields, try to match the value
                        $matchedKey = array_search($value, $field['choices']);
                        if ($matchedKey !== false) {
                            $customFieldValues[$name] = $matchedKey;
                        } else {
                            // Try to use value directly as key
                            if (isset($field['choices'][$value])) {
                                $customFieldValues[$name] = $value;
                            }
                        }
                    } else {
                        // Text/memo fields
                        $customFieldValues[$name] = (string) $value;
                    }
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
