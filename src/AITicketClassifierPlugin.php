<?php
/**
 * AI Ticket Classifier Plugin for osTicket
 *
 * Automatically classifies tickets using AI (OpenAI/Anthropic) by assigning
 * priority, topic/category, and filling custom form fields.
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.topic.php');
require_once(INCLUDE_DIR . 'class.priority.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
require_once(__DIR__ . '/ClassifierConfig.php');
require_once(__DIR__ . '/AIClient.php');

class AITicketClassifierPlugin extends Plugin {
    var $config_class = 'AITicketClassifierPluginConfig';

    // Cache of the active instance config
    private static $active_config = null;

    /**
     * Bootstrap the plugin and register signal handlers
     */
    function bootstrap() {
        // Cache this instance's config
        $cfg = $this->getConfig();
        if ($cfg) {
            self::$active_config = $cfg;
        }

        // Register signal handlers
        // 1) Classify new tickets when created
        if ($cfg && $cfg->get('classify_on_create')) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'), 'Ticket');
        }

        // 2) Reclassify tickets when customer sends a new message
        if ($cfg && $cfg->get('classify_on_message')) {
            Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
        }
    }

    /**
     * Gets the active plugin configuration
     *
     * @return PluginConfig|null Active configuration instance
     */
    public static function getActiveConfig() {
        return self::$active_config;
    }

    /**
     * Signal handler: Classify ticket when created
     *
     * @param Ticket $ticket Newly created ticket
     * @param array $data Additional data (unused)
     */
    function onTicketCreated($ticket, &$data = null) {
        $cfg = self::$active_config;
        if (!$cfg) return;

        try {
            // Get the initial message from the ticket thread
            $message = $this->getLatestCustomerMessage($ticket);
            if (!$message) {
                $message = $ticket->getSubject();
            }

            // Build full content for classification
            $content = "Subject: " . $ticket->getSubject() . "\n\n" . $message;

            // Classify the ticket
            $this->classifyTicket($ticket, $content, $cfg);

        } catch (Exception $e) {
            $this->handleError($e, $cfg);
        }
    }

    /**
     * Signal handler: Reclassify ticket when customer sends new message
     *
     * @param ThreadEntry $entry New thread entry
     * @param array $data Additional data (unused)
     */
    function onThreadEntryCreated($entry, &$data = null) {
        $cfg = self::$active_config;
        if (!$cfg) return;

        // Only process customer messages (type 'M')
        if ($entry->getType() !== 'M') {
            return;
        }

        try {
            // Get the ticket from the thread entry
            $thread = $entry->getThread();
            if (!$thread) return;

            $ticket = $thread->getObject();
            if (!$ticket || !($ticket instanceof Ticket)) {
                return;
            }

            // Get the message content
            $message = $this->cleanMessageBody($entry->getBody());
            if (!$message) return;

            // Build content for classification
            $content = "Subject: " . $ticket->getSubject() . "\n\n" . $message;

            // Classify the ticket
            $this->classifyTicket($ticket, $content, $cfg);

        } catch (Exception $e) {
            $this->handleError($e, $cfg);
        }
    }

    /**
     * Classify a ticket using AI
     *
     * @param Ticket $ticket The ticket to classify
     * @param string $content The content to analyze
     * @param PluginConfig $cfg Plugin configuration
     */
    private function classifyTicket($ticket, $content, $cfg) {
        // Get configuration values
        $provider = $cfg->get('ai_provider') ?: 'openai';
        $apiKey = $cfg->get('api_key');
        $model = $cfg->get('model') ?: 'gpt-4o-mini';
        $timeout = (int) ($cfg->get('timeout') ?: 30);

        if (!$apiKey) {
            throw new Exception('API key not configured');
        }

        // Get available topics
        $topics = $this->getAvailableTopics();

        // Get available priorities
        $priorities = $this->getAvailablePriorities();

        // Get custom fields to fill
        $customFields = array();
        if ($cfg->get('custom_fields')) {
            $customFields = $this->getCustomFieldDefinitions($ticket, $cfg->get('custom_fields'));
        }

        // Create AI client and classify
        $client = new AIClassifierClient($provider, $apiKey, $model, $timeout);
        $result = $client->classify($content, $topics, $priorities, $customFields);

        // Apply classification results
        $this->applyClassification($ticket, $result, $cfg);
    }

    /**
     * Apply classification results to ticket
     *
     * @param Ticket $ticket The ticket to update
     * @param array $result Classification result from AI
     * @param PluginConfig $cfg Plugin configuration
     */
    private function applyClassification($ticket, $result, $cfg) {
        $updated = false;

        // Set topic if enabled and result contains valid topic_id
        if ($cfg->get('classify_topic') && $result['topic_id']) {
            $ticket->topic_id = $result['topic_id'];
            $updated = true;
        }

        // Set priority if enabled and result contains valid priority_id
        if ($cfg->get('classify_priority') && $result['priority_id']) {
            // Priority is set via the form system
            $forms = DynamicFormEntry::forTicket($ticket->getId());
            foreach ($forms as $form) {
                $form->setAnswer('priority', null, $result['priority_id']);
                $form->saveAnswers();
            }
            $updated = true;
        }

        // Set custom fields if any
        if (!empty($result['custom_fields'])) {
            $forms = DynamicFormEntry::forTicket($ticket->getId());
            foreach ($forms as $form) {
                foreach ($result['custom_fields'] as $fieldName => $value) {
                    $form->setAnswer($fieldName, $value);
                }
                $form->saveAnswers();
            }
            $updated = true;
        }

        // Save ticket if updated
        if ($updated) {
            $ticket->save();
        }
    }

    /**
     * Get available topics for classification
     *
     * @return array Topics as [id => name]
     */
    private function getAvailableTopics() {
        $topics = array();

        try {
            $query = Topic::objects()->filter(array('active' => 1));
            foreach ($query as $topic) {
                $topics[$topic->getId()] = $topic->getName();
            }
        } catch (Exception $e) {
            // Fallback if query fails
        }

        return $topics;
    }

    /**
     * Get available priorities for classification
     *
     * @return array Priorities as [id => name]
     */
    private function getAvailablePriorities() {
        $priorities = array();

        try {
            $list = Priority::getPriorities();
            foreach ($list as $id => $desc) {
                $priorities[$id] = $desc;
            }
        } catch (Exception $e) {
            // Fallback if query fails
        }

        return $priorities;
    }

    /**
     * Get custom field definitions for AI
     *
     * @param Ticket $ticket The ticket
     * @param mixed $selectedFields Configured field names (string or array)
     * @return array Field definitions [name => [type, label, choices?]]
     */
    private function getCustomFieldDefinitions($ticket, $selectedFields) {
        $definitions = array();

        // Parse selected fields
        if (is_string($selectedFields)) {
            // Could be JSON or comma-separated
            $decoded = json_decode($selectedFields, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $selectedFields = $decoded;
            } else {
                $selectedFields = array_map('trim', explode(',', $selectedFields));
            }
        }

        if (!is_array($selectedFields) || empty($selectedFields)) {
            return $definitions;
        }

        // Supported field types
        $supportedTypes = array('text', 'memo', 'choices', 'bool');

        try {
            $forms = DynamicFormEntry::forTicket($ticket->getId());
            foreach ($forms as $form) {
                $fields = $form->getFields();
                foreach ($fields as $field) {
                    $name = $field->get('name');
                    $id = $field->get('id');
                    $type = $field->get('type');
                    $label = $field->get('label');

                    // Check if this field is selected
                    $key = $name ?: 'field_' . $id;
                    if (!in_array($key, $selectedFields)) {
                        continue;
                    }

                    // Check if type is supported
                    if (!in_array($type, $supportedTypes)) {
                        continue;
                    }

                    $def = array(
                        'type' => $type,
                        'label' => $label ?: $name,
                    );

                    // For choice fields, get available choices
                    if ($type === 'choices') {
                        $choices = array();
                        if (method_exists($field, 'getChoices')) {
                            $choices = $field->getChoices();
                        }
                        $def['choices'] = $choices;
                    }

                    $definitions[$key] = $def;
                }
            }
        } catch (Exception $e) {
            // If forms aren't available, return empty
        }

        return $definitions;
    }

    /**
     * Get the latest customer message from a ticket
     *
     * @param Ticket $ticket The ticket
     * @return string|null Message content or null if not found
     */
    private function getLatestCustomerMessage($ticket) {
        try {
            $thread = $ticket->getThread();
            if (!$thread) return null;

            $entries = $thread->getEntries();
            if (!$entries) return null;

            // Get entries and find the latest customer message
            $entries = clone $entries;
            $entries->order_by('-created');

            foreach ($entries as $entry) {
                if ($entry->getType() === 'M') {
                    return $this->cleanMessageBody($entry->getBody());
                }
            }
        } catch (Exception $e) {
            // Fallback
        }

        return null;
    }

    /**
     * Clean message body from HTML
     *
     * @param string $body Raw message body
     * @return string Clean text
     */
    private function cleanMessageBody($body) {
        if (!$body) return '';

        // Use osTicket's ThreadEntryBody cleaner if available
        if (class_exists('ThreadEntryBody')) {
            return ThreadEntryBody::clean($body);
        }

        // Fallback: strip HTML tags
        $text = strip_tags($body);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Handle errors based on configuration
     *
     * @param Exception $e The exception
     * @param PluginConfig $cfg Plugin configuration
     */
    private function handleError($e, $cfg) {
        $errorHandling = $cfg->get('error_handling') ?: 'log';

        if ($errorHandling === 'log') {
            global $ost;
            if ($ost) {
                $ost->logError(
                    'AI Ticket Classifier',
                    'Classification failed: ' . $e->getMessage(),
                    false // Don't send alert email
                );
            }
        }
        // For 'silent' mode, no logging - ticket remains unclassified
    }
}
