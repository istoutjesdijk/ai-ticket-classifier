<?php
/**
 * AI Ticket Classifier Plugin for osTicket
 *
 * Automatically classifies tickets using AI (OpenAI/Anthropic) by assigning
 * priority, topic/category, and filling custom form fields.
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.signal.php');
require_once(__DIR__ . '/ClassifierConfig.php');
require_once(__DIR__ . '/ClassifierService.php');

class AITicketClassifierPlugin extends Plugin {

    public $config_class = 'AITicketClassifierPluginConfig';

    /** @var PluginConfig|null Cached config instance */
    private static $activeConfig = null;

    /**
     * Bootstrap the plugin - register signal handlers
     */
    public function bootstrap() {
        $cfg = $this->getConfig();
        if ($cfg) {
            self::$activeConfig = $cfg;
        }

        // Register signal handlers
        Signal::connect('ticket.created', array($this, 'onTicketCreated'), 'Ticket');
        Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));

        // Register UI and AJAX handlers
        Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'), 'Ticket');
        Signal::connect('ajax.scp', array($this, 'onAjaxScp'));
    }

    /**
     * Get active plugin configuration
     *
     * @return PluginConfig|null
     */
    public static function getActiveConfig() {
        return self::$activeConfig;
    }

    /**
     * Handle ticket.created signal
     *
     * @param Ticket $ticket
     */
    public function onTicketCreated($ticket, &$data = null) {
        $cfg = self::$activeConfig;
        if (!$cfg || !$cfg->get('classify_on_create')) {
            return;
        }

        try {
            $service = new ClassifierService($cfg);
            $service->debugLog("Classifying new ticket #{$ticket->getNumber()}");
            $service->classifyTicket($ticket);
            $service->debugLog("Classification complete for #{$ticket->getNumber()}");
        } catch (Exception $e) {
            $service = new ClassifierService($cfg);
            $service->logError($e);
        }
    }

    /**
     * Handle threadentry.created signal
     *
     * @param ThreadEntry $entry
     */
    public function onThreadEntryCreated($entry, &$data = null) {
        $cfg = self::$activeConfig;
        if (!$cfg || !$cfg->get('classify_on_message')) {
            return;
        }

        // Only process customer messages
        if ($entry->getType() !== 'M') {
            return;
        }

        // Skip first message (handled by onTicketCreated)
        $thread = $entry->getThread();
        if (!$thread) {
            return;
        }

        $entries = $thread->getEntries();
        if ($entries && $entries->count() <= 1) {
            return;
        }

        $ticket = $thread->getObject();
        if (!$ticket || !($ticket instanceof Ticket)) {
            return;
        }

        try {
            $service = new ClassifierService($cfg);
            $service->debugLog("Reclassifying ticket #{$ticket->getNumber()} on new message");
            $service->classifyTicket($ticket);
        } catch (Exception $e) {
            $service = new ClassifierService($cfg);
            $service->logError($e);
        }
    }

    /**
     * Add "Classify with AI" to ticket More menu
     *
     * @param Ticket $ticket
     */
    public function onTicketViewMore($ticket, &$data) {
        global $thisstaff, $ost;

        if (!$thisstaff || !$thisstaff->isStaff()) {
            return;
        }
        if (!$ticket || !method_exists($ticket, 'getId')) {
            return;
        }

        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_EDIT)) {
            return;
        }

        $ticketId = (int) $ticket->getId();
        $csrf = $ost ? $ost->getCSRF()->getToken() : '';
        ?>
        <li>
            <a class="ai-classify-ticket" href="#" onclick="aiClassifyTicket(<?php echo $ticketId; ?>); return false;">
                <i class="icon-magic"></i> <?php echo __('Classify with AI'); ?>
            </a>
        </li>
        <script>
        function aiClassifyTicket(ticketId) {
            var $link = $('a.ai-classify-ticket');
            $link.html('<i class="icon-spinner icon-spin"></i> <?php echo __('Classifying...'); ?>');

            $.post('ajax.php/ai-classifier/classify', {
                ticket_id: ticketId,
                __CSRFToken__: '<?php echo $csrf; ?>'
            }, function(response) {
                if (response.ok) {
                    alert('<?php echo __('Classification complete:'); ?>\n' + response.message);
                    location.reload();
                } else {
                    alert('<?php echo __('Error:'); ?> ' + response.error);
                }
            }, 'json').fail(function(xhr) {
                var error = '<?php echo __('Request failed'); ?>';
                try { error = JSON.parse(xhr.responseText).error || error; } catch(e) {}
                alert('<?php echo __('Error:'); ?> ' + error);
            }).always(function() {
                $link.html('<i class="icon-magic"></i> <?php echo __('Classify with AI'); ?>');
            });
        }
        </script>
        <?php
    }

    /**
     * Register AJAX routes
     *
     * @param object $dispatcher
     */
    public function onAjaxScp($dispatcher) {
        require_once(__DIR__ . '/ClassifierAjax.php');
        $dispatcher->append(url_post('^/ai-classifier/classify$', array('ClassifierAjaxController', 'classify')));
    }
}
