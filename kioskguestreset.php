<?php
/**
 * Module Kiosque – KioskGuestReset
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *
 * Compatible PrestaShop 8.x et 9.x
 * Compatible Multi-boutique
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class KioskGuestReset extends Module
{
    const CONFIG_PIN_HASH        = 'KGR_PIN_HASH';
    const CONFIG_COOKIE_DURATION = 'KGR_COOKIE_DURATION';
    const CONFIG_REDIRECT_URL    = 'KGR_REDIRECT_URL';
    const CONFIG_SHOP_LABEL      = 'KGR_SHOP_LABEL';
    const CONFIG_SECRET_KEY      = 'KGR_SECRET_KEY';
    const CONFIG_REDIRECT_DELAY  = 'KGR_REDIRECT_DELAY';

    public function __construct()
    {
        $this->name                   = 'kioskguestreset';
        $this->tab                    = 'administration';
        $this->version                = '1.0.0';
        $this->author                 = 'Cyrille Mohr - Digital Food System';
        $this->need_instance          = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Kiosque Borne Magasin');
        $this->description = $this->l(
            'Transforme votre boutique PrestaShop en borne de commande en magasin. '
            . 'Activation sécurisée par PIN, nettoyage automatique après chaque commande.'
        );

        $this->confirmUninstall = $this->l(
            'Êtes-vous sûr de vouloir désinstaller le module Kiosque ? '
            . 'La configuration sera supprimée.'
        );
    }

    // -------------------------------------------------------------------------
    // INSTALL / UNINSTALL
    // -------------------------------------------------------------------------

    public function install(): bool
    {
        return parent::install()
            && $this->createSqlTables()
            && $this->registerHooks()
            && $this->setDefaultConfiguration();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->dropSqlTables()
            && $this->deleteConfiguration();
    }

    private function createSqlTables(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'kgr_pin_attempts` (
            `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip`         VARCHAR(45)      NOT NULL,
            `id_shop`    INT(11) UNSIGNED NOT NULL DEFAULT 1,
            `attempts`   TINYINT(3)       NOT NULL DEFAULT 0,
            `last_attempt` DATETIME       NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ip_shop` (`ip`, `id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function dropSqlTables(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'kgr_pin_attempts`'
        );
    }

    private function registerHooks(): bool
    {
        $hooks = [
            'displayOrderConfirmation',
            'sendMailAlterTemplateVars',
            'displayPDFInvoice',
            'displayHeader',
            'actionValidateOrder',
        ];

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function setDefaultConfiguration(): bool
    {
        // Clé secrète unique : générée seulement si absente (ne pas invalider les cookies actifs lors d'une réinstallation)
        $existingKey = Configuration::get(self::CONFIG_SECRET_KEY);
        if (empty($existingKey)) {
            Configuration::updateValue(self::CONFIG_SECRET_KEY, bin2hex(random_bytes(32)));
        }

        // Ne pas écraser les valeurs déjà configurées
        if (!Configuration::get(self::CONFIG_PIN_HASH)) {
            Configuration::updateValue(self::CONFIG_PIN_HASH, '');
        }
        if (!Configuration::get(self::CONFIG_COOKIE_DURATION)) {
            Configuration::updateValue(self::CONFIG_COOKIE_DURATION, 86400);
        }
        if (!Configuration::get(self::CONFIG_REDIRECT_URL)) {
            Configuration::updateValue(self::CONFIG_REDIRECT_URL, '/');
        }
        if (!Configuration::get(self::CONFIG_SHOP_LABEL)) {
            Configuration::updateValue(self::CONFIG_SHOP_LABEL, 'Borne en boutique');
        }
        if (!Configuration::get(self::CONFIG_REDIRECT_DELAY)) {
            Configuration::updateValue(self::CONFIG_REDIRECT_DELAY, 5);
        }

        return true;
    }

    private function deleteConfiguration(): bool
    {
        $keys = [
            self::CONFIG_PIN_HASH,
            self::CONFIG_COOKIE_DURATION,
            self::CONFIG_REDIRECT_URL,
            self::CONFIG_SHOP_LABEL,
            self::CONFIG_SECRET_KEY,
            self::CONFIG_REDIRECT_DELAY,
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // BACKOFFICE CONFIGURATION
    // -------------------------------------------------------------------------

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitKioskConfig')) {
            $output .= $this->saveConfiguration();
        }

        return $output . $this->renderConfigForm();
    }

    private function saveConfiguration(): string
    {
        $pin           = Tools::getValue('KGR_PIN');
        $cookieDuration = (int) Tools::getValue(self::CONFIG_COOKIE_DURATION);
        $redirectUrl   = Tools::getValue(self::CONFIG_REDIRECT_URL);
        $shopLabel     = Tools::getValue(self::CONFIG_SHOP_LABEL);
        $redirectDelay = (int) Tools::getValue(self::CONFIG_REDIRECT_DELAY);

        // Validation PIN (4 chiffres)
        if (!empty($pin)) {
            if (!preg_match('/^\d{4}$/', $pin)) {
                return $this->displayError(
                    $this->l('Le PIN doit contenir exactement 4 chiffres.')
                );
            }
            // Stockage haché
            $idShop = (int) $this->context->shop->id;
            Configuration::updateValue(
                self::CONFIG_PIN_HASH,
                hash('sha256', $pin . '_kgr_' . $idShop),
                false,
                null,
                $idShop
            );
        }

        if ($cookieDuration < 3600) {
            return $this->displayError(
                $this->l('La durée du cookie doit être d\'au moins 3600 secondes (1 heure).')
            );
        }

        if ($redirectDelay < 1 || $redirectDelay > 60) {
            return $this->displayError(
                $this->l('Le délai de redirection doit être compris entre 1 et 60 secondes.')
            );
        }

        $idShop = (int) $this->context->shop->id;

        Configuration::updateValue(self::CONFIG_COOKIE_DURATION, $cookieDuration, false, null, $idShop);
        Configuration::updateValue(self::CONFIG_REDIRECT_URL, $redirectUrl, false, null, $idShop);
        Configuration::updateValue(self::CONFIG_SHOP_LABEL, $shopLabel, false, null, $idShop);
        Configuration::updateValue(self::CONFIG_REDIRECT_DELAY, $redirectDelay, false, null, $idShop);

        return $this->displayConfirmation(
            $this->l('Configuration sauvegardée avec succès.')
        );
    }

    private function renderConfigForm(): string
    {
        $idShop         = (int) $this->context->shop->id;
        $cookieDuration = (int) Configuration::get(self::CONFIG_COOKIE_DURATION, null, null, $idShop);
        $redirectUrl    = Configuration::get(self::CONFIG_REDIRECT_URL, null, null, $idShop);
        $shopLabel      = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);
        $redirectDelay  = (int) Configuration::get(self::CONFIG_REDIRECT_DELAY, null, null, $idShop);
        $isPinSet       = !empty(Configuration::get(self::CONFIG_PIN_HASH, null, null, $idShop));

        $kioskUrl = $this->context->link->getModuleLink($this->name, 'borne');

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration du mode Kiosque'),
                    'icon'  => 'icon-tablet',
                ],
                'description' => sprintf(
                    $this->l('URL de la borne : %s'),
                    '<strong><a href="' . $kioskUrl . '" target="_blank">' . $kioskUrl . '</a></strong>'
                ),
                'input' => [
                    [
                        'type'     => 'text',
                        'label'    => $this->l('Nouveau PIN (4 chiffres)'),
                        'name'     => 'KGR_PIN',
                        'required' => false,
                        'hint'     => $isPinSet
                            ? $this->l('Un PIN est déjà configuré. Remplissez ce champ uniquement pour le modifier.')
                            : $this->l('Aucun PIN configuré. Veuillez en définir un.'),
                        'class'    => 'fixed-width-sm',
                        'maxlength' => 4,
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Label de la boutique'),
                        'name'  => self::CONFIG_SHOP_LABEL,
                        'hint'  => $this->l('Ex: Borne en boutique – Strasbourg. Affiché sur les commandes, emails et factures.'),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('URL de redirection post-activation et post-commande'),
                        'name'  => self::CONFIG_REDIRECT_URL,
                        'hint'  => $this->l('URL vers laquelle la borne est redirigée après activation et après chaque commande. Défaut : /'),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Durée du cookie kiosque'),
                        'name'    => self::CONFIG_COOKIE_DURATION,
                        'options' => [
                            'query' => [
                                ['id' => 3600,   'name' => $this->l('1 heure')],
                                ['id' => 7200,   'name' => $this->l('2 heures')],
                                ['id' => 14400,  'name' => $this->l('4 heures')],
                                ['id' => 28800,  'name' => $this->l('8 heures')],
                                ['id' => 43200,  'name' => $this->l('12 heures')],
                                ['id' => 86400,  'name' => $this->l('24 heures (défaut)')],
                                ['id' => 172800, 'name' => $this->l('48 heures')],
                                ['id' => 604800, 'name' => $this->l('7 jours')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                        'hint' => $this->l('Durée pendant laquelle la tablette reste en mode kiosque sans devoir ressaisir le PIN.'),
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Délai avant réinitialisation (après commande)'),
                        'name'    => self::CONFIG_REDIRECT_DELAY,
                        'options' => [
                            'query' => [
                                ['id' => 3,  'name' => '3 secondes'],
                                ['id' => 5,  'name' => '5 secondes (défaut)'],
                                ['id' => 10, 'name' => '10 secondes'],
                                ['id' => 15, 'name' => '15 secondes'],
                                ['id' => 30, 'name' => '30 secondes'],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                        'hint' => $this->l('Temps affiché sur la page de confirmation avant que la borne soit réinitialisée pour le client suivant.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Sauvegarder'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper                        = new HelperForm();
        $helper->show_toolbar          = false;
        $helper->table                 = $this->table;
        $helper->module                = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->identifier            = $this->identifier;
        $helper->submit_action         = 'submitKioskConfig';
        $helper->currentIndex          = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token                 = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars              = [
            'fields_value' => [
                'KGR_PIN'                      => '',
                self::CONFIG_COOKIE_DURATION   => $cookieDuration ?: 86400,
                self::CONFIG_REDIRECT_URL      => $redirectUrl ?: '/',
                self::CONFIG_SHOP_LABEL        => $shopLabel ?: 'Borne en boutique',
                self::CONFIG_REDIRECT_DELAY    => $redirectDelay ?: 5,
            ],
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        $infoHtml = '
        <div class="panel">
            <div class="panel-heading"><i class="icon-info-circle"></i> ' . $this->l('Informations et instructions') . '</div>
            <div class="panel-body">
                <h4>' . $this->l('Comment utiliser la borne ?') . '</h4>
                <ol>
                    <li>' . $this->l('Sur la tablette, ouvrez l\'URL :') . ' <code>' . $kioskUrl . '</code></li>
                    <li>' . $this->l('Saisissez le PIN à 4 chiffres configuré ci-dessous.') . '</li>
                    <li>' . $this->l('La tablette est redirigée vers la boutique en mode kiosque.') . '</li>
                    <li>' . $this->l('Après chaque commande, la session est automatiquement nettoyée.') . '</li>
                </ol>
                <h4>' . $this->l('Variables email disponibles') . '</h4>
                <p>' . $this->l('Pour identifier les commandes kiosque dans vos templates email, ajoutez ces variables :') . '</p>
                <ul>
                    <li><code>{kgr_is_borne}</code> – ' . $this->l('1 si commande kiosque, 0 sinon') . '</li>
                    <li><code>{kgr_order_origin}</code> – ' . $this->l('Ex: Borne en boutique – Strasbourg') . '</li>
                    <li><code>{kgr_shop_name}</code> – ' . $this->l('Nom de la boutique PrestaShop') . '</li>
                </ul>
                <p><em>' . $this->l('Exemple dans votre template order_conf.html :') . '</em></p>
                <pre>{if $kgr_is_borne == \'1\'}&lt;p&gt;Mode de commande : {$kgr_order_origin}&lt;/p&gt;{/if}</pre>
            </div>
        </div>';

        return $infoHtml . $helper->generateForm([$fieldsForm]);
    }

    // -------------------------------------------------------------------------
    // UTILITY: Kiosk Cookie Management
    // -------------------------------------------------------------------------

    public function isKioskModeActive(): bool
    {
        // Vérification via la session PHP (prioritaire pour les hooks Symfony PS9)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['kgr_kiosk_active'])) {
            return true;
        }

        // Vérification via le cookie HTTP natif
        $cookieValue = isset($_COOKIE['kgr_kiosk_active']) ? $_COOKIE['kgr_kiosk_active'] : '';
        if (empty($cookieValue)) {
            return false;
        }

        $idShop    = (int) $this->context->shop->id;
        $secretKey = Configuration::get(self::CONFIG_SECRET_KEY, null, null, $idShop);

        return $this->validateKioskCookie($cookieValue, $idShop, $secretKey);
    }

    public static function generateKioskCookie(int $idShop, string $secretKey): string
    {
        $timestamp = time();
        $payload   = $idShop . '|' . $timestamp;
        $hmac      = hash_hmac('sha256', $payload, $secretKey);

        return base64_encode($payload . '|' . $hmac);
    }

    public static function validateKioskCookie(string $cookieValue, int $idShop, string $secretKey): bool
    {
        $decoded = base64_decode($cookieValue, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return false;
        }

        [$storedShopId, $timestamp, $storedHmac] = $parts;

        if ((int) $storedShopId !== $idShop) {
            return false;
        }

        // Vérification de l'expiration
        $duration = (int) Configuration::get(self::CONFIG_COOKIE_DURATION, null, null, $idShop);
        if ($duration <= 0) {
            $duration = 86400;
        }
        if ((time() - (int) $timestamp) > $duration) {
            return false;
        }

        // Vérification HMAC
        $expectedHmac = hash_hmac('sha256', $storedShopId . '|' . $timestamp, $secretKey);

        return hash_equals($expectedHmac, $storedHmac);
    }

    public function isKioskOrder(Order $order): bool
    {
        return !empty($order->note)
            && strpos($order->note, 'Commande via borne') !== false;
    }

    // -------------------------------------------------------------------------
    // HOOKS
    // -------------------------------------------------------------------------

    /**
     * Hook displayHeader : inclure le CSS kiosque si mode actif ET synchroniser la session
     */
    public function hookDisplayHeader(array $params): void
    {
        if (!$this->isKioskModeActive()) {
            return;
        }

        // Propager l'état kiosque dans la session PHP pour les hooks Symfony (actionOrderAdd)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['kgr_kiosk_active'] = true;

        $this->context->controller->addCSS(
            $this->_path . 'views/css/kiosk.css'
        );
    }

    /**
     * Hook hookActionValidateOrder : tentative d'écriture de la note (contexte FO)
     * Note : ce hook peut ne pas avoir accès au cookie dans tous les cas PS9.
     * La note est aussi écrite dans hookDisplayOrderConfirmation en fallback.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!$this->isKioskModeActive()) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }

        $this->writeKioskOrderNote($order);
    }

    /**
     * Hook displayOrderConfirmation : page de confirmation + JS de réinitialisation kiosque
     * C'est ici qu'on écrit aussi la note interne car ce hook a TOUJOURS accès au cookie.
     */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        if (!$this->isKioskModeActive()) {
            return '';
        }

        // Récupérer la commande depuis les params
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            // Compatibilité PS8 : la commande est dans order_detail
            $orderDetail = $params['objOrder'] ?? null;
            if ($orderDetail instanceof Order) {
                $order = $orderDetail;
            }
        }

        // Écrire la note interne ici (fallback fiable, cookie disponible)
        if ($order instanceof Order && empty($order->note)) {
            $this->writeKioskOrderNote($order);
        }

        $idShop        = (int) $this->context->shop->id;
        $redirectDelay = (int) Configuration::get(self::CONFIG_REDIRECT_DELAY, null, null, $idShop);
        $redirectUrl   = Configuration::get(self::CONFIG_REDIRECT_URL, null, null, $idShop);
        $shopLabel     = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);
        $secretKey     = Configuration::get(self::CONFIG_SECRET_KEY, null, null, $idShop);
        $cookieDuration = (int) Configuration::get(self::CONFIG_COOKIE_DURATION, null, null, $idShop);

        if (empty($redirectUrl)) {
            $redirectUrl = '/';
        }

        // Génération du nouveau cookie kiosque pour le prochain client
        $newCookieValue = self::generateKioskCookie($idShop, $secretKey);

        // URL de reset AJAX
        $resetUrl = $this->context->link->getModuleLink($this->name, 'reset');

        $this->context->smarty->assign([
            'kgr_redirect_delay'    => $redirectDelay,
            'kgr_redirect_url'      => $redirectUrl,
            'kgr_shop_label'        => $shopLabel,
            'kgr_reset_url'         => $resetUrl,
            'kgr_new_cookie_value'  => $newCookieValue,
            'kgr_cookie_duration'   => $cookieDuration,
        ]);

        return $this->display(__FILE__, 'views/templates/front/order_confirmation_kiosk.tpl');
    }

    /**
     * Écrit la note interne kiosque sur la commande (champ note + message privé BO)
     */
    private function writeKioskOrderNote(Order $order): void
    {
        $idShop    = (int) $order->id_shop ?: (int) $this->context->shop->id;
        $shopLabel = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);
        $date      = date('d/m/Y H:i');

        $noteText = 'Commande via borne – ' . $shopLabel . ' – ' . $date;

        // Champ note de la commande (utilisé par isKioskOrder pour identifier les commandes)
        Db::getInstance()->update(
            'orders',
            ['note' => pSQL($noteText)],
            'id_order = ' . (int) $order->id
        );

        // Message privé visible dans le BO (section Messages)
        $message              = new Message();
        $message->message     = $noteText;
        $message->id_order    = (int) $order->id;
        $message->id_customer = (int) $order->id_customer;
        $message->private     = 1;
        $message->add();
    }


    /**
     * Hook sendMailAlterTemplateVars : injecter les variables kiosque dans les emails
     */
    public function hookSendMailAlterTemplateVars(array $params): array
    {
        $order = null;

        // Récupération de la commande depuis les params
        if (isset($params['template_vars']['{id_order}'])) {
            $orderId = (int) $params['template_vars']['{id_order}'];
            $order   = new Order($orderId);
        }

        if ($order instanceof Order && $order->id && $this->isKioskOrder($order)) {
            $idShop    = (int) $order->id_shop;
            $shopLabel = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);
            $shopName  = (new Shop($idShop))->name;

            $params['template_vars']['{kgr_is_borne}']      = '1';
            $params['template_vars']['{kgr_order_origin}']  = $shopLabel;
            $params['template_vars']['{kgr_shop_name}']     = $shopName;
        } else {
            $params['template_vars']['{kgr_is_borne}']      = '0';
            $params['template_vars']['{kgr_order_origin}']  = '';
            $params['template_vars']['{kgr_shop_name}']     = '';
        }

        return $params;
    }

    /**
     * Hook displayPDFInvoice : ajouter une ligne "Mode de commande : Borne" sur le PDF
     */
    public function hookDisplayPDFInvoice(array $params): string
    {
        if (!isset($params['object'])) {
            return '';
        }

        $invoice = $params['object'];
        $order   = isset($invoice->order) ? $invoice->order : null;

        if (!$order instanceof Order || !$this->isKioskOrder($order)) {
            return '';
        }

        $idShop    = (int) $order->id_shop;
        $shopLabel = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);

        $this->context->smarty->assign([
            'kgr_pdf_label' => $shopLabel,
        ]);

        return $this->display(__FILE__, 'views/templates/front/pdf_invoice_kiosk.tpl');
    }
}
