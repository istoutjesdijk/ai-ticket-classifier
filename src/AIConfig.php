<?php
/**
 * AI Ticket Classifier - Configuration Constants
 *
 * Central place for all default values and configuration.
 */

class AIConfig {

    // API Endpoints
    const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    // Default model settings
    const DEFAULT_PROVIDER = 'openai';
    const DEFAULT_MODEL = 'gpt-4o-mini';
    const DEFAULT_TIMEOUT = 30;
    const DEFAULT_TEMPERATURE = 1.0;
    const DEFAULT_MAX_TOKENS = 500;

    // Supported field types for custom field classification
    const SUPPORTED_FIELD_TYPES = array('text', 'memo', 'choices', 'bool');

    // Legacy OpenAI models that use max_tokens instead of max_completion_tokens
    const LEGACY_MODEL_PATTERN = '/^gpt-(3\.5|4$)/i';

    // Models that don't support temperature parameter
    const NO_TEMPERATURE_PATTERN = '/^(gpt-5|o1|o3)/i';
}
