<?php
/**
 * AI Ticket Classifier - Configuration Constants
 *
 * Central place for all default values and configuration.
 */

class AIConfig {

    // API Endpoints
    const OPENAI_URL = 'https://api.openai.com/v1/responses';
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    // Default model settings
    const DEFAULT_PROVIDER = 'openai';
    const DEFAULT_MODEL = 'gpt-5-nano';
    const DEFAULT_TIMEOUT = 10;
    const DEFAULT_TEMPERATURE = 1.0;
    const DEFAULT_MAX_TOKENS = 1000;
    const DEFAULT_STORE_RESPONSES = true;
    const DEFAULT_REASONING_EFFORT = null;

    // Supported field types for custom field classification
    const SUPPORTED_FIELD_TYPES = array('text', 'memo', 'choices', 'bool');

    // Models that don't support temperature parameter (o-series reasoning models)
    const NO_TEMPERATURE_PATTERN = '/^(o1|o3)/i';
}
