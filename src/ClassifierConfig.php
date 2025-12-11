<?php
/**
 * AI Ticket Classifier Plugin - Configuration
 *
 * Defines configuration fields for the plugin admin panel.
 */

require_once(INCLUDE_DIR . 'class.forms.php');
require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
require_once(__DIR__ . '/AIConfig.php');

class AITicketClassifierPluginConfig extends PluginConfig {

    /**
     * Form options
     */
    public function getFormOptions() {
        return array(
            'title' => __('AI Ticket Classifier Settings'),
            'instructions' => __('Configure AI-powered automatic ticket classification.'),
        );
    }

    /**
     * Configuration fields
     */
    public function getFields() {
        $fields = array();

        // --- AI Provider ---
        $fields['section_provider'] = new SectionBreakField(array(
            'label' => __('AI Provider Settings'),
        ));

        $fields['ai_provider'] = new ChoiceField(array(
            'label' => __('AI Provider'),
            'required' => true,
            'default' => 'openai',
            'choices' => array(
                'openai' => __('OpenAI'),
                'anthropic' => __('Anthropic'),
            ),
            'hint' => __('Select which AI provider to use.'),
        ));

        $fields['api_key'] = new TextboxField(array(
            'label' => __('API Key'),
            'required' => true,
            'hint' => __('Your API key for the selected provider.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['model'] = new TextboxField(array(
            'label' => __('Model'),
            'hint' => __('OpenAI: gpt-4o-mini, gpt-4o. Anthropic: claude-3-haiku-20240307'),
            'configuration' => array(
                'size' => 40,
                'length' => 100,
                'placeholder' => AIConfig::DEFAULT_MODEL,
            ),
        ));

        // --- Triggers ---
        $fields['section_triggers'] = new SectionBreakField(array(
            'label' => __('Classification Triggers'),
        ));

        $fields['classify_on_create'] = new BooleanField(array(
            'label' => __('Classify New Tickets'),
            'default' => true,
            'configuration' => array(
                'desc' => __('Automatically classify tickets when created.')
            )
        ));

        $fields['classify_on_message'] = new BooleanField(array(
            'label' => __('Reclassify on Customer Reply'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Reclassify when customer sends a new message.')
            )
        ));

        // --- Options ---
        $fields['section_options'] = new SectionBreakField(array(
            'label' => __('Classification Options'),
        ));

        $fields['classify_priority'] = new BooleanField(array(
            'label' => __('Set Priority'),
            'default' => true,
            'configuration' => array(
                'desc' => __('Allow AI to set ticket priority.')
            )
        ));

        $fields['classify_topic'] = new BooleanField(array(
            'label' => __('Set Topic/Category'),
            'default' => true,
            'configuration' => array(
                'desc' => __('Allow AI to set ticket topic.')
            )
        ));

        // --- Custom Fields ---
        $customFields = $this->getCustomFieldChoices();
        if (!empty($customFields)) {
            $fields['section_custom_fields'] = new SectionBreakField(array(
                'label' => __('AI-Managed Custom Fields'),
                'hint' => __('Select which custom fields the AI should fill.'),
            ));

            foreach ($customFields as $name => $label) {
                $fields['cf_' . $name] = new BooleanField(array(
                    'label' => $label,
                    'default' => false,
                    'configuration' => array(
                        'desc' => sprintf(__('Let AI fill "%s"'), $label)
                    )
                ));
            }
        }

        // --- Error Handling ---
        $fields['section_errors'] = new SectionBreakField(array(
            'label' => __('Error Handling'),
        ));

        $fields['error_handling'] = new ChoiceField(array(
            'label' => __('On API Error'),
            'default' => 'log',
            'choices' => array(
                'log' => __('Log to System Logs'),
                'silent' => __('Silent (no logging)'),
            ),
            'hint' => __('How to handle classification errors.'),
        ));

        $fields['debug_logging'] = new BooleanField(array(
            'label' => __('Debug Logging'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Log all activity (disable in production).')
            )
        ));

        // --- Advanced ---
        $fields['section_advanced'] = new SectionBreakField(array(
            'label' => __('Advanced Settings'),
        ));

        $fields['temperature'] = new TextboxField(array(
            'label' => __('Temperature'),
            'default' => AIConfig::DEFAULT_TEMPERATURE,
            'hint' => __('Randomness (0-2). Note: gpt-5 and o-series only support 1.'),
            'configuration' => array(
                'size' => 10,
                'length' => 10,
                'placeholder' => (string) AIConfig::DEFAULT_TEMPERATURE,
            ),
        ));

        $fields['timeout'] = new TextboxField(array(
            'label' => __('API Timeout (seconds)'),
            'default' => AIConfig::DEFAULT_TIMEOUT,
            'hint' => __('Maximum time to wait for AI response.'),
            'configuration' => array(
                'size' => 10,
                'length' => 10,
                'placeholder' => (string) AIConfig::DEFAULT_TIMEOUT,
            ),
        ));

        $fields['max_tokens'] = new TextboxField(array(
            'label' => __('Max Output Tokens'),
            'default' => AIConfig::DEFAULT_MAX_TOKENS,
            'hint' => __('Maximum tokens in AI response.'),
            'configuration' => array(
                'size' => 10,
                'length' => 10,
                'placeholder' => (string) AIConfig::DEFAULT_MAX_TOKENS,
            ),
        ));

        $fields['store_responses'] = new BooleanField(array(
            'label' => __('Store API Responses'),
            'default' => AIConfig::DEFAULT_STORE_RESPONSES,
            'configuration' => array(
                'desc' => __('Store responses in OpenAI dashboard for debugging.')
            )
        ));

        $fields['reasoning_effort'] = new TextboxField(array(
            'label' => __('Reasoning Effort'),
            'hint' => __('Options: none, minimal, low, medium, high, xhigh. For gpt-5 and o-series models.'),
            'configuration' => array(
                'size' => 10,
                'length' => 10,
                'placeholder' => AIConfig::DEFAULT_REASONING_EFFORT,
            ),
        ));

        return $fields;
    }

    /**
     * Get available custom fields from ticket form
     */
    private function getCustomFieldChoices() {
        $choices = array();

        try {
            $ticketForm = TicketForm::getInstance();
            if (!$ticketForm) {
                return $choices;
            }

            foreach ($ticketForm->getDynamicFields() as $field) {
                $type = $field->get('type');
                $name = $field->get('name');
                $label = $field->get('label');
                $id = $field->get('id');

                if (!in_array($type, AIConfig::SUPPORTED_FIELD_TYPES) || !$id) {
                    continue;
                }

                $key = $name ?: 'field_' . $id;
                $displayLabel = ($label ?: $name ?: 'Field #' . $id) . " ({$type})";
                $choices[$key] = $displayLabel;
            }
        } catch (Exception $e) {
            // Forms not available
        }

        return $choices;
    }

    /**
     * Validate before save
     */
    public function pre_save(&$config, &$errors) {
        if (empty($config['api_key'])) {
            $errors['api_key'] = __('API key is required.');
            return false;
        }

        if (!empty($config['timeout']) && !is_numeric($config['timeout'])) {
            $errors['timeout'] = __('Timeout must be a number.');
            return false;
        }

        if (!empty($config['temperature']) && !is_numeric($config['temperature'])) {
            $errors['temperature'] = __('Temperature must be a number.');
            return false;
        }

        if (!empty($config['max_tokens']) && !is_numeric($config['max_tokens'])) {
            $errors['max_tokens'] = __('Max tokens must be a number.');
            return false;
        }

        return true;
    }
}
