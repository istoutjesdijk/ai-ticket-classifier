<?php
/**
 * AI Ticket Classifier Plugin for osTicket
 *
 * Automatically classifies tickets using AI (OpenAI/Anthropic) by assigning
 * priority, topic/category, and filling custom form fields.
 */
return array(
    'id'             => 'ai-ticket-classifier:osticket',
    'version'        => '1.0.0',
    'name'           => 'AI Ticket Classifier',
    'description'    => 'Automatically classify tickets using AI (OpenAI/Anthropic). Assigns priority, topic/category, and fills custom form fields based on ticket content.',
    'author'         => 'Ide Stoutjesdijk',
    'ost_version'    => MAJOR_VERSION,
    'plugin'         => 'src/AITicketClassifierPlugin.php:AITicketClassifierPlugin',
    'include_path'   => '',
);
