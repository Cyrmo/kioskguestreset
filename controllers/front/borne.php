<?php
/**
 * Contrôleur front : page d'activation du mode kiosque (PIN)
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class KioskGuestResetBorneModuleFrontController extends ModuleFrontController
{
    /** @var string */
    public $php_self = 'module-kioskguestreset-borne';

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = true;

    /** @var bool */
    public $display_footer = true;

    /**
     * Désactiver la redirection canonique pour éviter les boucles sur PS9
     */
    protected function canonicalRedirection(string $canonical_url = ''): void
    {
        // Ne rien faire : pas de redirection canonique pour la page PIN kiosque
    }

    // Nombre maximum de tentatives PIN avant blocage temporaire
    const MAX_ATTEMPTS    = 5;
    // Durée du blocage en secondes (10 minutes)
    const LOCKOUT_SECONDS = 600;

    public function initContent(): void
    {
        parent::initContent();

        $idShop = (int) $this->context->shop->id;

        // Si le mode kiosque est déjà actif (cookie valide), rediriger directement vers la boutique
        if (!Tools::isSubmit('submitPIN') && $this->module->isKioskModeActive()) {
            $redirectUrl = Configuration::get(
                KioskGuestReset::CONFIG_REDIRECT_URL,
                null, null, $idShop
            );
            if (empty($redirectUrl)) {
                $redirectUrl = $this->context->link->getPageLink('index');
            }
            Tools::redirect($redirectUrl);
            return;
        }

        $error   = '';
        $success = false;

        if (Tools::isSubmit('submitPIN')) {
            $result = $this->handlePinSubmission($idShop);
            if ($result['success']) {
                $success = true;
            } else {
                $error = $result['error'];
            }
        }

        // Si activation réussie, on redirige
        if ($success) {
            $redirectUrl = Configuration::get(
                KioskGuestReset::CONFIG_REDIRECT_URL,
                null, null, $idShop
            );
            if (empty($redirectUrl)) {
                $redirectUrl = $this->context->link->getPageLink('index');
            }
            Tools::redirect($redirectUrl);
            return;
        }

        $this->context->smarty->assign([
            'kgr_error'        => $error,
            'kgr_kiosk_active' => $this->module->isKioskModeActive(),
            'module_dir'       => $this->module->getPathUri(),
        ]);

        $this->setTemplate('module:kioskguestreset/views/templates/front/borne.tpl');
    }

    private function handlePinSubmission(int $idShop): array
    {
        // Vérification brute-force
        // Note : pas de token CSRF ici — la sécurité est assurée par :
        // 1. Le PIN lui-même (seul le personnel le connaît)
        // 2. La protection brute-force (5 tentatives / 10 min par IP)
        // Le token CSRF basé sur $_SESSION est incompatible avec la gestion de session PS9/Symfony
        $ip = $this->getClientIp();
        if ($this->isLocked($ip, $idShop)) {
            return [
                'success' => false,
                'error'   => $this->module->l(
                    'Trop de tentatives. Réessayez dans 10 minutes.',
                    'borne'
                ),
            ];
        }

        $pin = Tools::getValue('kgr_pin');

        // Validation format (4 chiffres)
        if (!preg_match('/^\d{4}$/', $pin)) {
            $this->incrementAttempts($ip, $idShop);
            return [
                'success' => false,
                'error'   => $this->module->l('Le PIN doit contenir exactement 4 chiffres.', 'borne'),
            ];
        }

        $storedHash = Configuration::get(
            KioskGuestReset::CONFIG_PIN_HASH,
            null, null, $idShop
        );

        if (empty($storedHash)) {
            return [
                'success' => false,
                'error'   => $this->module->l('Aucun PIN configuré. Veuillez contacter l\'administrateur.', 'borne'),
            ];
        }

        $inputHash = hash('sha256', $pin . '_kgr_' . $idShop);

        if (!hash_equals($storedHash, $inputHash)) {
            $this->incrementAttempts($ip, $idShop);
            $remaining = $this->getRemainingAttempts($ip, $idShop);
            $errorMsg  = $this->module->l('PIN incorrect.', 'borne');
            if ($remaining <= 2) {
                $errorMsg .= ' ' . sprintf(
                    $this->module->l('%d tentative(s) restante(s) avant blocage temporaire.', 'borne'),
                    $remaining
                );
            }
            return ['success' => false, 'error' => $errorMsg];
        }

        // PIN correct : réinitialiser les tentatives
        $this->resetAttempts($ip, $idShop);

        // Poser le cookie kiosque
        $secretKey      = Configuration::get(KioskGuestReset::CONFIG_SECRET_KEY, null, null, $idShop);
        $cookieDuration = (int) Configuration::get(KioskGuestReset::CONFIG_COOKIE_DURATION, null, null, $idShop);
        if ($cookieDuration <= 0) {
            $cookieDuration = 86400;
        }

        $cookieValue = KioskGuestReset::generateKioskCookie($idShop, $secretKey);

        $cookieOptions = [
            'expires'  => time() + $cookieDuration,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false, // JS doit pouvoir lire le cookie pour le nettoyage
            'samesite' => 'Strict',
        ];

        // setcookie avec options (PHP 7.3+, compatible PS8/9)
        setcookie('kgr_kiosk_active', $cookieValue, $cookieOptions);

        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Brute-force protection (table SQL)
    // -------------------------------------------------------------------------

    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    private function isLocked(string $ip, int $idShop): bool
    {
        $row = Db::getInstance()->getRow(
            'SELECT attempts, last_attempt FROM `' . _DB_PREFIX_ . 'kgr_pin_attempts`
             WHERE ip = \'' . pSQL($ip) . '\' AND id_shop = ' . $idShop
        );

        if (!$row) {
            return false;
        }

        if ($row['attempts'] >= self::MAX_ATTEMPTS) {
            $elapsed = time() - strtotime($row['last_attempt']);
            if ($elapsed < self::LOCKOUT_SECONDS) {
                return true;
            }
            // Lockout expiré : reset
            $this->resetAttempts($ip, $idShop);
        }

        return false;
    }

    private function incrementAttempts(string $ip, int $idShop): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'kgr_pin_attempts`
                (ip, id_shop, attempts, last_attempt)
             VALUES
                (\'' . pSQL($ip) . '\', ' . $idShop . ', 1, NOW())
             ON DUPLICATE KEY UPDATE
                attempts = IF(
                    TIMESTAMPDIFF(SECOND, last_attempt, NOW()) >= ' . self::LOCKOUT_SECONDS . ',
                    1,
                    attempts + 1
                ),
                last_attempt = NOW()'
        );
    }

    private function resetAttempts(string $ip, int $idShop): void
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'kgr_pin_attempts`
             WHERE ip = \'' . pSQL($ip) . '\' AND id_shop = ' . $idShop
        );
    }

    private function getRemainingAttempts(string $ip, int $idShop): int
    {
        $row = Db::getInstance()->getRow(
            'SELECT attempts FROM `' . _DB_PREFIX_ . 'kgr_pin_attempts`
             WHERE ip = \'' . pSQL($ip) . '\' AND id_shop = ' . $idShop
        );
        $attempts = (int) ($row['attempts'] ?? 0);
        return max(0, self::MAX_ATTEMPTS - $attempts);
    }
}
