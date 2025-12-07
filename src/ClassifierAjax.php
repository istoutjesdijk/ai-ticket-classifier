<?php
/**
 * AI Ticket Classifier - AJAX Controller
 *
 * Handles manual classification requests from the ticket view.
 */

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');

class ClassifierAjaxController extends AjaxController {

    /**
     * Validates CSRF token for POST requests
     */
    private function validateCSRF() {
        global $ost;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            return;

        $token = $_POST['__CSRFToken__'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
        if (!$ost || !$ost->getCSRF() || !$ost->getCSRF()->validateToken($token)) {
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('CSRF token validation failed'))));
        }
    }

    /**
     * Manually classify a ticket
     */
    function classify() {
        global $thisstaff, $ost;

        // Staff only
        $this->staffOnly();
        $this->validateCSRF();

        // Get ticket
        $ticket_id = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticket_id || !($ticket = Ticket::lookup($ticket_id))) {
            Http::response(404, $this->encode(array('ok' => false, 'error' => __('Ticket not found'))));
        }

        // Permission check
        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_EDIT)) {
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('Permission denied'))));
        }

        // Get plugin config
        $cfg = AITicketClassifierPlugin::getActiveConfig();
        if (!$cfg) {
            Http::response(500, $this->encode(array('ok' => false, 'error' => __('Plugin not configured'))));
        }

        try {
            // Get the latest customer message
            $message = null;
            $thread = $ticket->getThread();
            if ($thread) {
                $entries = clone $thread->getEntries();
                $entries->order_by('-created');
                foreach ($entries as $entry) {
                    if ($entry->getType() === 'M') {
                        $message = $this->cleanMessageBody($entry->getBody());
                        break;
                    }
                }
            }

            if (!$message) {
                $message = $ticket->getSubject();
            }

            $content = "Subject: " . $ticket->getSubject() . "\n\n" . $message;

            // Get configuration
            $provider = $cfg->get('ai_provider') ?: 'openai';
            $apiKey = $cfg->get('api_key');
            $model = $cfg->get('model') ?: 'gpt-4o-mini';
            $timeout = (int) ($cfg->get('timeout') ?: 30);
            $temperature = (float) ($cfg->get('temperature') ?: 1.0);

            if (!$apiKey) {
                Http::response(500, $this->encode(array('ok' => false, 'error' => __('API key not configured'))));
            }

            // Debug log helper
            $debugLog = function($msg) use ($cfg, $ost) {
                if ($cfg->get('debug_logging') && $ost) {
                    $ost->logDebug('AI Ticket Classifier', $msg);
                }
            };

            $debugLog("=== Manual classification started for ticket #{$ticket->getNumber()} ===");

            // Get available topics and priorities using osTicket's native functions
            $topics = Topic::getHelpTopics(false, false);
            $priorities = Priority::getPriorities();
            $debugLog("Topics: " . count($topics) . ", Priorities: " . count($priorities));

            // Get custom fields
            $customFields = array();
            $selectedFields = $this->getSelectedCustomFields($cfg, $debugLog);
            if (!empty($selectedFields)) {
                $customFields = $this->getCustomFieldDefinitions($ticket, $selectedFields, $debugLog);
            }
            $debugLog("Custom fields to fill: " . count($customFields));

            // Call AI
            require_once(__DIR__ . '/AIClient.php');
            $client = new AIClassifierClient($provider, $apiKey, $model, $timeout, $temperature);
            $debugLog("Calling AI API with provider: {$provider}, model: {$model}");
            $result = $client->classify($content, $topics, $priorities, $customFields);
            $debugLog("AI response received: topic_id={$result['topic_id']}, priority_id={$result['priority_id']}, custom_fields=" . count($result['custom_fields']));

            // Apply results
            $changes = array();

            if ($cfg->get('classify_topic') && $result['topic_id']) {
                $oldTopic = $ticket->getTopic() ? $ticket->getTopic()->getName() : 'None';
                $ticket->topic_id = $result['topic_id'];
                $newTopic = isset($topics[$result['topic_id']]) ? $topics[$result['topic_id']] : 'Unknown';
                $changes[] = "Topic: {$oldTopic} → {$newTopic}";
            }

            if ($cfg->get('classify_priority') && $result['priority_id']) {
                $oldPriority = $ticket->getPriority() ? $ticket->getPriority()->getDesc() : 'None';
                $forms = DynamicFormEntry::forTicket($ticket->getId());
                foreach ($forms as $form) {
                    $form->setAnswer('priority', null, $result['priority_id']);
                    $form->saveAnswers();
                }
                $newPriority = isset($priorities[$result['priority_id']]) ? $priorities[$result['priority_id']] : 'Unknown';
                $changes[] = "Priority: {$oldPriority} → {$newPriority}";
            }

            // Apply custom fields
            if (!empty($result['custom_fields'])) {
                $forms = DynamicFormEntry::forTicket($ticket->getId());
                foreach ($forms as $form) {
                    foreach ($result['custom_fields'] as $fieldName => $value) {
                        $form->setAnswer($fieldName, $value);
                        $changes[] = "Field {$fieldName}: {$value}";
                    }
                    $form->saveAnswers();
                }
            }

            $ticket->save();

            // Log if debug enabled
            if ($cfg->get('debug_logging') && $ost) {
                $ost->logDebug('AI Ticket Classifier', "Manual classification for ticket #{$ticket->getNumber()}: " . implode(', ', $changes));
            }

            $message = empty($changes) ? __('No changes made') : implode("\n", $changes);
            Http::response(200, $this->encode(array(
                'ok' => true,
                'message' => $message,
                'topic_id' => $result['topic_id'],
                'priority_id' => $result['priority_id']
            )));

        } catch (Exception $e) {
            if ($ost) {
                $ost->logError('AI Ticket Classifier', 'Manual classification failed: ' . $e->getMessage());
            }
            Http::response(500, $this->encode(array('ok' => false, 'error' => $e->getMessage())));
        }
    }

    /**
     * Clean message body from HTML
     */
    private function cleanMessageBody($body) {
        if (!$body) return '';
        if (class_exists('ThreadEntryBody')) {
            return ThreadEntryBody::clean($body);
        }
        $text = strip_tags($body);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Get selected custom fields from config checkboxes
     */
    private function getSelectedCustomFields($cfg, $debugLog = null) {
        $selected = array();
        $supportedTypes = array('text', 'memo', 'choices', 'bool');

        try {
            $ticketForm = TicketForm::getInstance();
            if (!$ticketForm) {
                if ($debugLog) $debugLog("getSelectedCustomFields: TicketForm not available");
                return $selected;
            }

            $fields = $ticketForm->getDynamicFields();
            if ($debugLog) $debugLog("getSelectedCustomFields: Found " . count($fields) . " dynamic fields");

            foreach ($fields as $field) {
                $type = $field->get('type');
                $name = $field->get('name');
                $id = $field->get('id');

                if (!in_array($type, $supportedTypes) || !$id) {
                    continue;
                }

                $fieldName = $name ?: 'field_' . $id;
                $configKey = 'cf_' . $fieldName;
                $configValue = $cfg->get($configKey);

                if ($debugLog) $debugLog("  Checking {$configKey}: " . ($configValue ? 'ENABLED' : 'disabled'));

                if ($configValue) {
                    $selected[] = $fieldName;
                }
            }
        } catch (Exception $e) {
            if ($debugLog) $debugLog("getSelectedCustomFields ERROR: " . $e->getMessage());
        }

        return $selected;
    }

    /**
     * Get custom field definitions for AI
     */
    private function getCustomFieldDefinitions($ticket, $selectedFields, $debugLog = null) {
        $definitions = array();
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

                    $key = $name ?: 'field_' . $id;
                    if (!in_array($key, $selectedFields)) {
                        continue;
                    }

                    if (!in_array($type, $supportedTypes)) {
                        continue;
                    }

                    $def = array(
                        'type' => $type,
                        'label' => $label ?: $name,
                    );

                    if ($type === 'choices') {
                        $choices = array();
                        if (method_exists($field, 'getChoices')) {
                            $choices = $field->getChoices();
                        }
                        $def['choices'] = $choices;
                    }

                    $definitions[$key] = $def;
                    if ($debugLog) $debugLog("  Custom field defined: {$key} (type={$type}, label={$label})");
                }
            }
        } catch (Exception $e) {
            if ($debugLog) $debugLog("getCustomFieldDefinitions ERROR: " . $e->getMessage());
        }

        return $definitions;
    }
}
