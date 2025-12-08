<?php
/**
 * AI Ticket Classifier - Classification Service
 *
 * Core classification logic shared between automatic and manual classification.
 * Uses only osTicket native functions for all operations.
 */

require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.topic.php');
require_once(INCLUDE_DIR . 'class.priority.php');
require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
require_once(__DIR__ . '/AIClient.php');

class ClassifierService {

    /** @var array Supported custom field types */
    const SUPPORTED_FIELD_TYPES = array('text', 'memo', 'choices', 'bool');

    /** @var PluginConfig */
    private $config;

    /**
     * @param PluginConfig $config Plugin configuration
     */
    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Classify a ticket and apply results
     *
     * @param Ticket $ticket The ticket to classify
     * @return array Changes made [field => "old → new"]
     * @throws Exception On classification error
     */
    public function classifyTicket($ticket) {
        $content = $this->buildTicketContent($ticket);
        $result = $this->callAI($content, $ticket);
        return $this->applyResults($ticket, $result);
    }

    /**
     * Build content string from ticket for AI analysis
     *
     * @param Ticket $ticket
     * @return string
     */
    public function buildTicketContent($ticket) {
        $message = $this->getLatestCustomerMessage($ticket) ?? '';
        return "Subject: " . $ticket->getSubject() . "\n\n" . $message;
    }

    /**
     * Get the latest customer message from ticket
     *
     * @param Ticket $ticket
     * @return string|null
     */
    public function getLatestCustomerMessage($ticket) {
        $messages = $ticket->getMessages();
        if (!$messages) {
            return null;
        }

        $messages = clone $messages;
        $messages->order_by('-created');

        foreach ($messages as $message) {
            return $this->cleanMessageBody($message->getBody());
        }

        return null;
    }

    /**
     * Clean HTML from message body
     *
     * @param string $body
     * @return string
     */
    public function cleanMessageBody($body) {
        if (!$body) {
            return '';
        }

        return ThreadEntryBody::clean($body);
    }

    /**
     * Call AI API for classification
     *
     * @param string $content Ticket content
     * @param Ticket $ticket For custom field definitions
     * @return array Classification result
     * @throws Exception On API error
     */
    private function callAI($content, $ticket) {
        $provider = $this->config->get('ai_provider') ?: 'openai';
        $apiKey = $this->config->get('api_key');
        $model = $this->config->get('model') ?: 'gpt-4o-mini';
        $timeout = (int) ($this->config->get('timeout') ?: 30);
        $temperature = (float) ($this->config->get('temperature') ?: 1.0);

        if (!$apiKey) {
            throw new Exception('API key not configured');
        }

        // Get classification options using osTicket native functions
        $topics = Topic::getHelpTopics(false, false);
        $priorities = Priority::getPriorities();
        $customFields = $this->getCustomFieldDefinitions($ticket);

        $client = new AIClassifierClient($provider, $apiKey, $model, $timeout, $temperature);
        return $client->classify($content, $topics, $priorities, $customFields);
    }

    /**
     * Apply classification results to ticket
     *
     * @param Ticket $ticket
     * @param array $result AI classification result
     * @return array Changes made
     */
    private function applyResults($ticket, $result) {
        $changes = array();
        $topics = Topic::getHelpTopics(false, false);
        $priorities = Priority::getPriorities();

        // Load forms once for all updates
        $forms = DynamicFormEntry::forTicket($ticket->getId());

        // Apply topic
        if ($this->config->get('classify_topic') && $result['topic_id']) {
            $oldTopic = $ticket->getTopic() ? $ticket->getTopic()->getName() : 'None';
            $ticket->topic_id = $result['topic_id'];
            $newTopic = $topics[$result['topic_id']] ?? 'Unknown';
            $changes[] = "Topic: {$oldTopic} → {$newTopic}";
        }

        // Apply priority
        if ($this->config->get('classify_priority') && $result['priority_id']) {
            $oldPriority = $ticket->getPriority() ? $ticket->getPriority()->getDesc() : 'None';
            foreach ($forms as $form) {
                $form->setAnswer('priority', null, $result['priority_id']);
            }
            $newPriority = $priorities[$result['priority_id']] ?? 'Unknown';
            $changes[] = "Priority: {$oldPriority} → {$newPriority}";
        }

        // Apply custom fields
        if (!empty($result['custom_fields'])) {
            $this->debugLog("Custom fields from AI: " . Format::json_encode($result['custom_fields']));

            // Ensure forms are saved during ticket.created signal
            foreach ($forms as $form) {
                if (!$form->get('id')) {
                    $form->save();
                }
            }
            $forms = DynamicFormEntry::forTicket($ticket->getId());

            foreach ($forms as $form) {
                foreach ($form->getAnswers() as $answer) {
                    $field = $answer->getField();
                    $key = $field->get('name') ?: 'field_' . $field->get('id');

                    if (!isset($result['custom_fields'][$key])) {
                        continue;
                    }

                    $value = $result['custom_fields'][$key];
                    $type = $field->get('type');
                    $this->debugLog("Setting field '{$key}' (type={$type}) to: {$value}");

                    if ($type === 'choices') {
                        $answer->setValue(null, $value);
                    } else {
                        $answer->setValue($value);
                    }
                    $changes[] = "{$key}: {$value}";
                }
                $saved = $form->saveAnswers();
                $this->debugLog("saveAnswers() returned: " . ($saved ? 'true' : 'false'));
            }
        }

        $ticket->save();
        return $changes;
    }

    /**
     * Get selected custom field names from config
     *
     * @return array Field names
     */
    public function getSelectedCustomFields() {
        $selected = array();

        $ticketForm = TicketForm::getInstance();
        if (!$ticketForm) {
            return $selected;
        }

        foreach ($ticketForm->getDynamicFields() as $field) {
            $type = $field->get('type');
            $name = $field->get('name');
            $id = $field->get('id');

            if (!in_array($type, self::SUPPORTED_FIELD_TYPES) || !$id) {
                continue;
            }

            $fieldName = $name ?: 'field_' . $id;
            if ($this->config->get('cf_' . $fieldName)) {
                $selected[] = $fieldName;
            }
        }

        return $selected;
    }

    /**
     * Get custom field definitions for AI prompt
     *
     * @param Ticket $ticket
     * @return array Field definitions
     */
    private function getCustomFieldDefinitions($ticket) {
        $definitions = array();
        $selectedFields = $this->getSelectedCustomFields();

        if (empty($selectedFields)) {
            return $definitions;
        }

        $forms = DynamicFormEntry::forTicket($ticket->getId());
        foreach ($forms as $form) {
            foreach ($form->getFields() as $field) {
                $name = $field->get('name');
                $id = $field->get('id');
                $type = $field->get('type');
                $label = $field->get('label');

                $key = $name ?: 'field_' . $id;

                if (!in_array($key, $selectedFields)) {
                    continue;
                }
                if (!in_array($type, self::SUPPORTED_FIELD_TYPES)) {
                    continue;
                }

                $def = array(
                    'type' => $type,
                    'label' => $label ?: $name,
                );

                // Add field configuration
                $config = $field->getConfiguration();
                if (!empty($config['length'])) {
                    $def['max_length'] = (int) $config['length'];
                }
                if ($type === 'text' && !empty($config['validator'])) {
                    $def['validator'] = $config['validator'];
                }
                if ($type === 'choices' && method_exists($field, 'getChoices')) {
                    $def['choices'] = $field->getChoices();
                    if (!empty($config['multiselect'])) {
                        $def['multiselect'] = true;
                    }
                }

                $definitions[$key] = $def;
            }
        }

        return $definitions;
    }

    /**
     * Log debug message if enabled
     *
     * @param string $message
     */
    public function debugLog($message) {
        if (!$this->config->get('debug_logging')) {
            return;
        }

        global $ost;
        if ($ost) {
            // Use logWarning because logDebug is often filtered by osTicket
            $ost->logWarning('AI Classifier Debug', $message);
        }
    }

    /**
     * Log error based on config
     *
     * @param Exception $e
     */
    public function logError($e) {
        if ($this->config->get('error_handling') !== 'log') {
            return;
        }

        global $ost;
        if ($ost) {
            $ost->logError('AI Ticket Classifier', 'Classification failed: ' . $e->getMessage(), false);
        }
    }
}
