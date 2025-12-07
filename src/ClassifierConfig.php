<?php
/**
 * AI Ticket Classifier Plugin - Configuration
 *
 * Defines all configuration fields for the plugin admin panel.
 */

require_once(INCLUDE_DIR . 'class.forms.php');
require_once(INCLUDE_DIR . 'class.dynamic_forms.php');

class AITicketClassifierPluginConfig extends PluginConfig {

    /**
     * Returns configuration form options
     *
     * @return array Form configuration options
     */
    function getFormOptions() {
        return array(
            'title' => __('AI Ticket Classifier Settings'),
            'instructions' => __('Configure AI-powered automatic ticket classification. The plugin will assign priority, topic, and optionally fill custom form fields based on ticket content.'),
        );
    }

    /**
     * Returns configuration form fields
     *
     * @return array Configuration form fields
     */
    function getFields() {
        $fields = array();

        // --- AI Provider Settings ---
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
            'hint' => __('Select which AI provider to use for classification.'),
        ));

        $fields['api_key'] = new TextboxField(array(
            'label' => __('API Key'),
            'required' => true,
            'hint' => __('Your API key for the selected provider.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['model'] = new TextboxField(array(
            'label' => __('Model'),
            'required' => true,
            'default' => 'gpt-4o-mini',
            'hint' => __('Model to use. OpenAI: gpt-4o-mini, gpt-4o. Anthropic: claude-3-haiku-20240307, claude-3-5-sonnet-20241022'),
            'configuration' => array('size' => 40, 'length' => 100),
        ));

        // --- Classification Triggers ---
        $fields['section_triggers'] = new SectionBreakField(array(
            'label' => __('Classification Triggers'),
        ));

        $fields['classify_on_create'] = new BooleanField(array(
            'label' => __('Classify New Tickets'),
            'default' => true,
            'configuration' => array(
                'desc' => __('Automatically classify tickets when they are created.')
            )
        ));

        $fields['classify_on_message'] = new BooleanField(array(
            'label' => __('Reclassify on Customer Reply'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Reclassify ticket when customer sends a new message.')
            )
        ));

        // --- Classification Options ---
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
                'desc' => __('Allow AI to set ticket topic/category.')
            )
        ));

        // Custom fields - checkboxes for each available field
        $customFieldChoices = $this->getCustomFieldChoices();
        if (!empty($customFieldChoices)) {
            $fields['section_custom_fields'] = new SectionBreakField(array(
                'label' => __('AI-Managed Custom Fields'),
                'hint' => __('Select which custom form fields the AI should fill.'),
            ));

            foreach ($customFieldChoices as $fieldName => $fieldLabel) {
                $fields['cf_' . $fieldName] = new BooleanField(array(
                    'label' => $fieldLabel,
                    'default' => false,
                    'configuration' => array(
                        'desc' => sprintf(__('Let AI fill "%s"'), $fieldLabel)
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
            'required' => false,
            'default' => 'log',
            'choices' => array(
                'log' => __('Log to System Logs'),
                'silent' => __('Silent (no logging)'),
            ),
            'hint' => __('Log errors to Admin → Dashboard → System Logs, or fail silently.'),
        ));

        $fields['debug_logging'] = new BooleanField(array(
            'label' => __('Debug Logging'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Log all activity to System Logs (for troubleshooting). Disable in production.')
            )
        ));

        // --- Advanced Settings ---
        $fields['section_advanced'] = new SectionBreakField(array(
            'label' => __('Advanced Settings'),
        ));

        $fields['custom_prompt'] = new TextareaField(array(
            'label' => __('Custom Classification Prompt'),
            'required' => false,
            'hint' => __('Optional: Custom instructions for the AI. Leave empty to use the default prompt. Use %{ticket.subject} and %{ticket.message} as placeholders.'),
            'configuration' => array(
                'rows' => 6,
                'html' => false,
            ),
        ));

        $fields['temperature'] = new TextboxField(array(
            'label' => __('Temperature'),
            'required' => false,
            'default' => '1',
            'hint' => __('Controls randomness (0-2). Lower = more consistent. Note: gpt-5 and o-series models only support temperature 1.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['timeout'] = new TextboxField(array(
            'label' => __('API Timeout (seconds)'),
            'required' => false,
            'default' => '30',
            'hint' => __('Maximum time to wait for AI response.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        return $fields;
    }

    /**
     * Get available custom form fields for ticket forms
     *
     * @return array Field choices in format [field_id => label]
     */
    private function getCustomFieldChoices() {
        $choices = array();

        // Supported field types for AI classification
        $supportedTypes = array('text', 'memo', 'choices', 'bool');

        try {
            // Get the ticket form
            $ticketForm = TicketForm::getInstance();
            if (!$ticketForm) {
                return $choices;
            }

            // Get all dynamic fields from the ticket form
            $fields = $ticketForm->getDynamicFields();
            foreach ($fields as $field) {
                $type = $field->get('type');
                $name = $field->get('name');
                $label = $field->get('label');
                $id = $field->get('id');

                // Skip built-in fields and unsupported types
                if (!in_array($type, $supportedTypes)) {
                    continue;
                }

                // Skip fields without a name or ID
                if (!$id) {
                    continue;
                }

                // Use field name if available, otherwise use ID
                $key = $name ?: 'field_' . $id;
                $displayLabel = $label ?: $name ?: 'Field #' . $id;
                $displayLabel .= ' (' . $type . ')';

                $choices[$key] = $displayLabel;
            }
        } catch (Exception $e) {
            // If forms aren't available yet, return empty
        }

        return $choices;
    }

    /**
     * Validate configuration before saving
     *
     * @param array &$config Configuration values
     * @param array &$errors Error messages
     * @return bool True if valid
     */
    function pre_save(&$config, &$errors) {
        // Validate API key is provided
        if (empty($config['api_key'])) {
            $errors['api_key'] = __('API key is required.');
            return false;
        }

        // Validate model is provided
        if (empty($config['model'])) {
            $errors['model'] = __('Model name is required.');
            return false;
        }

        // Validate timeout is numeric
        if (!empty($config['timeout']) && !is_numeric($config['timeout'])) {
            $errors['timeout'] = __('Timeout must be a number.');
            return false;
        }

        return true;
    }
}
