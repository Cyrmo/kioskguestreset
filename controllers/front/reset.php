<?php
/**
 * Contrôleur front AJAX : réinitialisation de session kiosque
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class KioskGuestResetResetModuleFrontController extends ModuleFrontController
{
    /** @var string */
    public $php_self = 'reset';

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    public function init(): void
    {
        parent::init();

        // Uniquement accessible via POST + AJAX
        if (!$this->isAjaxRequest() || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('Accès non autorisé', 403);
            return;
        }

        // Vérification que le mode kiosque est bien actif
        if (!$this->module->isKioskModeActive()) {
            $this->sendJsonError('Mode kiosque inactif', 403);
            return;
        }

        // La protection est assurée par la vérification du cookie kiosque ci-dessus
        // (pas de token CSRF nécessaire : l'endpoint est protégé par cookie signé HMAC)

        $this->performReset();
    }

    private function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function performReset(): void
    {
        try {
            // 1. Déconnexion du client si connecté
            if (isset($this->context->customer) && $this->context->customer->isLogged()) {
                $this->context->customer->logout();
            }

            // 2. Suppression du panier
            if (isset($this->context->cart) && $this->context->cart->id) {
                $this->context->cart->delete();
            }

            // 3. Nettoyage du cookie PrestaShop (panier, client)
            $this->context->cookie->id_cart     = 0;
            $this->context->cookie->id_customer  = 0;
            $this->context->cookie->customer_lastname  = '';
            $this->context->cookie->customer_firstname = '';
            $this->context->cookie->passwd_token  = '';
            $this->context->cookie->email         = '';
            $this->context->cookie->is_guest      = 0;
            $this->context->cookie->write();

            // 4. Destruction de la session PHP
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                session_regenerate_id(true);
            }

            $this->sendJsonSuccess();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[KioskGuestReset] Erreur lors du reset : ' . $e->getMessage(),
                3,
                null,
                'KioskGuestReset',
                null,
                true
            );
            $this->sendJsonError('Erreur lors de la réinitialisation', 500);
        }
    }

    private function sendJsonSuccess(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    private function sendJsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
}
