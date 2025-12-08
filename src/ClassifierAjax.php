<?php
/**
 * AI Ticket Classifier - AJAX Controller
 *
 * Handles manual classification requests from the ticket view.
 */

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(__DIR__ . '/ClassifierService.php');

class ClassifierAjaxController extends AjaxController {

    /**
     * Manually classify a ticket
     */
    public function classify() {
        global $thisstaff, $ost;

        // Validate request
        $this->staffOnly();
        $this->validateCSRF();

        // Get and validate ticket
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $ticket = $ticketId ? Ticket::lookup($ticketId) : null;

        if (!$ticket) {
            return $this->error(404, __('Ticket not found'));
        }

        // Check permissions
        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_EDIT)) {
            return $this->error(403, __('Permission denied'));
        }

        // Get plugin config
        $cfg = AITicketClassifierPlugin::getActiveConfig();
        if (!$cfg) {
            return $this->error(500, __('Plugin not configured'));
        }

        // Classify using service
        try {
            $service = new ClassifierService($cfg);
            $changes = $service->classifyTicket($ticket);

            $service->debugLog("Manual classification for #{$ticket->getNumber()}: " . implode(', ', $changes));

            $message = empty($changes) ? __('No changes made') : implode("\n", $changes);
            return $this->success($message);

        } catch (Exception $e) {
            if ($ost) {
                $ost->logError('AI Ticket Classifier', 'Manual classification failed: ' . $e->getMessage());
            }
            return $this->error(500, $e->getMessage());
        }
    }

    /**
     * Validate CSRF token
     */
    private function validateCSRF() {
        global $ost;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $token = $_POST['__CSRFToken__'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
        if (!$token || !$ost->getCSRF()->validateToken($token)) {
            $this->error(403, __('CSRF token validation failed'));
        }
    }

    /**
     * Send success response
     *
     * @param string $message
     */
    private function success($message) {
        Http::response(200, $this->encode(array(
            'ok' => true,
            'message' => $message
        )));
    }

    /**
     * Send error response
     *
     * @param int $code HTTP status code
     * @param string $error Error message
     */
    private function error($code, $error) {
        Http::response($code, $this->encode(array(
            'ok' => false,
            'error' => $error
        )));
    }
}
