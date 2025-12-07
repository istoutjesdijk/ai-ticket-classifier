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

        // Always register signal handlers - check config in the handlers
        // This ensures signals are connected even if config loading has issues
        Signal::connect('ticket.created', array($this, 'onTicketCreated'), 'Ticket');
        Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));

        // Register UI hooks for manual classification
        Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'), 'Ticket');
        Signal::connect('ajax.scp', array($this, 'onAjaxScp'));

        // Debug log bootstrap
        if ($cfg && $cfg->get('debug_logging')) {
            global $ost;
            if ($ost) {
                $classifyCreate = $cfg->get('classify_on_create') ? 'ON' : 'OFF';
                $classifyMessage = $cfg->get('classify_on_message') ? 'ON' : 'OFF';
                $ost->logDebug('AI Ticket Classifier', "Plugin bootstrap complete. classify_on_create: {$classifyCreate}, classify_on_message: {$classifyMessage}");
            }
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
     * Signal handler: Add menu item to ticket "More" dropdown
     *
     * @param Ticket $ticket The ticket being viewed
     * @param array $data Menu data passed by reference
     */
    function onTicketViewMore($ticket, &$data) {
        global $thisstaff;

        // Only show for staff with edit permission
        if (!$thisstaff || !$thisstaff->isStaff()) return;
        if (!$ticket || !method_exists($ticket, 'getId')) return;

        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_EDIT)) return;

        // Get CSRF token
        global $ost;
        $csrf = $ost ? $ost->getCSRF()->getTokenName() . '=' . $ost->getCSRF()->getToken() : '';

        ?>
        <li>
            <a class="ai-classify-ticket" href="#"
               data-ticket-id="<?php echo (int)$ticket->getId(); ?>"
               onclick="aiClassifyTicket(<?php echo (int)$ticket->getId(); ?>, '<?php echo $csrf; ?>'); return false;">
                <i class="icon-magic"></i>
                <?php echo __('Classify with AI'); ?>
            </a>
        </li>
        <script type="text/javascript">
        function aiClassifyTicket(ticketId, csrf) {
            $.ajax({
                url: 'ajax.php/ai-classifier/classify',
                type: 'POST',
                data: {
                    ticket_id: ticketId,
                    __CSRFToken__: csrf.split('=')[1]
                },
                dataType: 'json',
                beforeSend: function() {
                    // Show loading
                    $('a.ai-classify-ticket').html('<i class="icon-spinner icon-spin"></i> <?php echo __('Classifying...'); ?>');
                },
                success: function(response) {
                    if (response.ok) {
                        alert('<?php echo __('Classification complete:'); ?>\n' + response.message);
                        location.reload();
                    } else {
                        alert('<?php echo __('Error:'); ?> ' + response.error);
                    }
                },
                error: function(xhr) {
                    var error = '<?php echo __('Request failed'); ?>';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.error) error = resp.error;
                    } catch(e) {}
                    alert('<?php echo __('Error:'); ?> ' + error);
                },
                complete: function() {
                    $('a.ai-classify-ticket').html('<i class="icon-magic"></i> <?php echo __('Classify with AI'); ?>');
                }
            });
        }
        </script>
        <?php
    }

    /**
     * Signal handler: Register AJAX routes
     *
     * @param object $dispatcher AJAX dispatcher
     */
    function onAjaxScp($dispatcher) {
        require_once(__DIR__ . '/ClassifierAjax.php');
        $dispatcher->append(url_post('^/ai-classifier/classify$', array('ClassifierAjaxController', 'classify')));
    }

    /**
     * Log debug message if debug logging is enabled
     *
     * @param string $message Debug message
     * @param PluginConfig $cfg Plugin configuration
     */
    private function debugLog($message, $cfg = null) {
        if (!$cfg) $cfg = self::$active_config;
        if (!$cfg || !$cfg->get('debug_logging')) return;

        global $ost;
        if ($ost) {
            // Use logWarning instead of logDebug for visibility
            $ost->logWarning('AI Classifier Debug', $message);
        }
    }

    /**
     * Signal handler: Classify ticket when created
     *
     * @param Ticket $ticket Newly created ticket
     * @param array $data Additional data (unused)
     */
    function onTicketCreated($ticket, &$data = null) {
        $cfg = self::$active_config;
        if (!$cfg) {
            // Log even without config for debugging
            global $ost;
            if ($ost) $ost->logDebug('AI Ticket Classifier', 'onTicketCreated: No config available');
            return;
        }

        // Check if classification on create is enabled
        if (!$cfg->get('classify_on_create')) {
            $this->debugLog("onTicketCreated: classify_on_create is OFF, skipping", $cfg);
            return;
        }

        $this->debugLog("=== onTicketCreated triggered for ticket #{$ticket->getNumber()} ===", $cfg);

        try {
            // Get the initial message from the ticket thread
            $message = $this->getLatestCustomerMessage($ticket);
            if (!$message) {
                $message = $ticket->getSubject();
                $this->debugLog("No message found, using subject only", $cfg);
            }

            // Build full content for classification
            $content = "Subject: " . $ticket->getSubject() . "\n\n" . $message;
            $this->debugLog("Content length: " . strlen($content) . " chars", $cfg);

            // Classify the ticket
            $this->classifyTicket($ticket, $content, $cfg);

        } catch (Exception $e) {
            $this->debugLog("ERROR: " . $e->getMessage(), $cfg);
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

        // Check if classification on message is enabled
        if (!$cfg->get('classify_on_message')) {
            return;
        }

        // Only process customer messages (type 'M')
        if ($entry->getType() !== 'M') {
            return;
        }

        // Skip if this is the first message (ticket just created)
        // to avoid double classification with onTicketCreated
        $thread = $entry->getThread();
        if ($thread) {
            $entries = $thread->getEntries();
            if ($entries && $entries->count() <= 1) {
                return;
            }
        }

        $this->debugLog("=== onThreadEntryCreated triggered ===", $cfg);

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
        $temperature = (float) ($cfg->get('temperature') ?: 1.0);

        $this->debugLog("Provider: {$provider}, Model: {$model}, Timeout: {$timeout}s, Temperature: {$temperature}", $cfg);

        if (!$apiKey) {
            throw new Exception('API key not configured');
        }
        $this->debugLog("API key present: " . strlen($apiKey) . " chars", $cfg);

        // Get available topics
        $topics = $this->getAvailableTopics();
        $this->debugLog("Available topics: " . count($topics) . (count($topics) > 0 ? " - " . implode(', ', $topics) : ""), $cfg);

        // Get available priorities
        $priorities = $this->getAvailablePriorities();
        $this->debugLog("Available priorities: " . count($priorities) . " - " . implode(', ', $priorities), $cfg);

        // Get custom fields to fill (from cf_* checkboxes)
        $customFields = array();
        $selectedFields = $this->getSelectedCustomFields($cfg);
        $this->debugLog("Selected custom fields from config: " . count($selectedFields) . " - " . implode(', ', $selectedFields), $cfg);
        if (!empty($selectedFields)) {
            $customFields = $this->getCustomFieldDefinitions($ticket, $selectedFields);
            $this->debugLog("Custom field definitions loaded: " . count($customFields), $cfg);
            foreach ($customFields as $name => $def) {
                $this->debugLog("  - {$name}: type={$def['type']}, label={$def['label']}", $cfg);
            }
        }

        // Create AI client and classify
        $this->debugLog("Calling AI API...", $cfg);
        $client = new AIClassifierClient($provider, $apiKey, $model, $timeout, $temperature);
        $result = $client->classify($content, $topics, $priorities, $customFields);
        $this->debugLog("AI response: topic_id={$result['topic_id']}, priority_id={$result['priority_id']}, custom_fields=" . count($result['custom_fields']), $cfg);

        // Apply classification results
        $this->applyClassification($ticket, $result, $cfg);
        $this->debugLog("=== Classification complete ===", $cfg);
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
     * Get selected custom fields from config checkboxes
     *
     * @param PluginConfig $cfg Plugin configuration
     * @return array Array of selected field names
     */
    private function getSelectedCustomFields($cfg) {
        $selected = array();

        // Get available field names from ticket form
        $supportedTypes = array('text', 'memo', 'choices', 'bool');

        try {
            $ticketForm = TicketForm::getInstance();
            if (!$ticketForm) {
                $this->debugLog("getSelectedCustomFields: TicketForm not available", $cfg);
                return $selected;
            }

            $fields = $ticketForm->getDynamicFields();
            $this->debugLog("getSelectedCustomFields: Found " . count($fields) . " dynamic fields", $cfg);

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

                $this->debugLog("  Checking {$configKey}: " . ($configValue ? 'ENABLED' : 'disabled'), $cfg);

                // Check if this field's checkbox is enabled
                if ($configValue) {
                    $selected[] = $fieldName;
                }
            }
        } catch (Exception $e) {
            $this->debugLog("getSelectedCustomFields ERROR: " . $e->getMessage(), $cfg);
        }

        return $selected;
    }

    /**
     * Get available topics for classification
     *
     * @return array Topics as [id => name]
     */
    private function getAvailableTopics() {
        // Use osTicket's native function: getHelpTopics($publicOnly, $disabled)
        // false, false = all non-public + only active topics
        return Topic::getHelpTopics(false, false);
    }

    /**
     * Get available priorities for classification
     *
     * @return array Priorities as [id => name]
     */
    private function getAvailablePriorities() {
        // Use osTicket's native function
        return Priority::getPriorities();
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
