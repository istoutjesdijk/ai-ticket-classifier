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

            if (!$apiKey) {
                Http::response(500, $this->encode(array('ok' => false, 'error' => __('API key not configured'))));
            }

            // Get available topics and priorities
            $topics = array();
            $topicQuery = Topic::objects()->filter(array('active' => 1));
            foreach ($topicQuery as $topic) {
                $topics[$topic->getId()] = $topic->getName();
            }

            $priorities = Priority::getPriorities();

            // Get custom fields
            $customFields = array();
            // Skip custom fields for manual classification to keep it simple

            // Call AI
            require_once(__DIR__ . '/AIClient.php');
            $client = new AIClassifierClient($provider, $apiKey, $model, $timeout);
            $result = $client->classify($content, $topics, $priorities, $customFields);

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
}
