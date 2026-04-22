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
        // Initialiser la configuration pour chaque boutique active
        // Chaque boutique a sa propre clé secrète (sécurité multi-boutique)
        foreach (Shop::getShops(true) as $shop) {
            $idShop  = (int) $shop['id_shop'];
            $idGroup = (int) $shop['id_shop_group'];

            // Clé secrète : générée seulement si absente pour cette boutique
            // (ne pas invalider les cookies actifs lors d'une réinstallation)
            if (!Configuration::get(self::CONFIG_SECRET_KEY, null, $idGroup, $idShop)) {
                Configuration::updateValue(
                    self::CONFIG_SECRET_KEY,
                    bin2hex(random_bytes(32)),
                    false,
                    $idGroup,
                    $idShop
                );
            }

            // URL de redirection : pointe vers la boutique par défaut
            if (!Configuration::get(self::CONFIG_REDIRECT_URL, null, $idGroup, $idShop)) {
                $shopObj    = new Shop($idShop);
                $defaultUrl = $shopObj->getBaseURL(true);
                Configuration::updateValue(self::CONFIG_REDIRECT_URL, $defaultUrl, false, $idGroup, $idShop);
            }

            if (!Configuration::get(self::CONFIG_PIN_HASH, null, $idGroup, $idShop)) {
                Configuration::updateValue(self::CONFIG_PIN_HASH, '', false, $idGroup, $idShop);
            }
            if (!Configuration::get(self::CONFIG_COOKIE_DURATION, null, $idGroup, $idShop)) {
                Configuration::updateValue(self::CONFIG_COOKIE_DURATION, 86400, false, $idGroup, $idShop);
            }
            if (!Configuration::get(self::CONFIG_SHOP_LABEL, null, $idGroup, $idShop)) {
                Configuration::updateValue(self::CONFIG_SHOP_LABEL, 'Borne en boutique', false, $idGroup, $idShop);
            }
            if (!Configuration::get(self::CONFIG_REDIRECT_DELAY, null, $idGroup, $idShop)) {
                Configuration::updateValue(self::CONFIG_REDIRECT_DELAY, 5, false, $idGroup, $idShop);
            }
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

        // Suppression pour chaque boutique (valeurs shop-spécifiques)
        foreach (Shop::getShops(false) as $shop) {
            $idShop  = (int) $shop['id_shop'];
            $idGroup = (int) $shop['id_shop_group'];
            foreach ($keys as $key) {
                Configuration::updateValue($key, null, false, $idGroup, $idShop);
            }
        }

        // Suppression des éventuelles valeurs globales (héritage)
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
                <h4>' . $this->l('Notifications email kiosque') . '</h4>
                <p>
                    <span class="label label-success"><i class="icon-check"></i> ' . $this->l('Configuré automatiquement') . '</span>
                </p>
                <p>' . $this->l('Le module injecte automatiquement un bloc de notification dans les emails de commande :') . '</p>
                <ul>
                    <li><strong>' . $this->l('Email client') . '</strong> (' . $this->l('order_conf') . ') — ' . $this->l('bloc bleu "Commande passée en magasin"') . '</li>
                    <li><strong>' . $this->l('Email admin') . '</strong> (' . $this->l('backoffice_order / new_order') . ') — ' . $this->l('bloc orange "COMMANDE BORNE EN BOUTIQUE"') . '</li>
                </ul>
                <p><em>' . $this->l('Aucune modification manuelle de vos templates email n\'est nécessaire.') . '</em></p>
            </div>
        </div>';

        return $infoHtml . $helper->generateForm([$fieldsForm]);
    }

    // -------------------------------------------------------------------------
    // UTILITY: Kiosk Cookie Management
    // -------------------------------------------------------------------------

    public function isKioskModeActive(): bool
    {
        // Le cookie HTTP natif est le seul mécanisme fiable :
        // disponible dans tous les contextes PHP (FO, hooks Symfony PS9, AJAX)
        $cookieValue = $_COOKIE['kgr_kiosk_active'] ?? '';
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
     * Hook displayHeader : inclure le CSS kiosque si mode actif
     */
    public function hookDisplayHeader(array $params): void
    {
        if (!$this->isKioskModeActive()) {
            return;
        }

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
     *
     * Deux sources de détection pour couvrir tous les cas :
     * - isKioskModeActive() : fiable pendant le checkout FO (email de confirmation immédiat)
     *   car le cookie est présent AVANT que la note soit écrite sur la commande.
     * - isKioskOrder()      : fiable pour les emails BO ultérieurs (expédition, remboursement…)
     *   qui s'envoient après que la note a été écrite sur la commande.
     *
     * Templates email supportés :
     * - order_conf      : confirmation client
     * - backoffice_order: notification admin (PS 1.7+/9)
     * - new_order       : alias possible selon version/config PS
     */
    public function hookSendMailAlterTemplateVars(array $params): array
    {
        // Initialiser toutes les variables à vide (évite les {var} non remplacés dans les templates)
        $params['template_vars']['{kgr_is_borne}']           = '0';
        $params['template_vars']['{kgr_order_origin}']       = '';
        $params['template_vars']['{kgr_shop_name}']          = '';
        $params['template_vars']['{kgr_email_block_client}'] = '';
        $params['template_vars']['{kgr_email_block_bo}']     = '';

        // Limiter aux templates concernés (compatibilité multi-versions PS)
        $supportedTemplates = ['order_conf', 'backoffice_order', 'new_order'];
        if (!isset($params['template']) || !in_array($params['template'], $supportedTemplates, true)) {
            return $params;
        }

        // Identifier la commande
        $order = null;
        if (isset($params['template_vars']['{id_order}'])) {
            $orderId = (int) $params['template_vars']['{id_order}'];
            $order   = new Order($orderId);
        }

        $isKiosk = $this->isKioskModeActive()
            || ($order instanceof Order && $order->id && $this->isKioskOrder($order));

        if (!$isKiosk) {
            return $params;
        }

        // Récupération du label boutique avec fallback
        $idShop    = ($order instanceof Order && $order->id_shop)
            ? (int) $order->id_shop
            : (int) $this->context->shop->id;
        $shopLabel = Configuration::get(self::CONFIG_SHOP_LABEL, null, null, $idShop);
        $shopLabel = ($shopLabel !== false && $shopLabel !== '')
            ? $shopLabel
            : $this->l('Borne en boutique');
        $shopName  = (new Shop($idShop))->name;

        // Variables simples (rétrocompatibilité avec templates utilisant déjà ces variables)
        $params['template_vars']['{kgr_is_borne}']     = '1';
        $params['template_vars']['{kgr_order_origin}'] = $shopLabel;
        $params['template_vars']['{kgr_shop_name}']    = $shopName;

        $shopLabelEsc = htmlspecialchars($shopLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Bloc HTML client : fond bleu, ton informatif
        // Inséré dans order_conf.html via {kgr_email_block_client}
        // Note : les emails PS n'acceptent pas les conditionnelles Smarty {if},
        // le bloc est donc construit en PHP et injecté pré-formaté.
        $params['template_vars']['{kgr_email_block_client}'] =
            '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 50px 20px;">'
            . '<div style="padding:15px 20px;background-color:#f0f7ff;'
            . 'border-left:4px solid #0070bf;'
            . 'font-family:Open sans,Arial,sans-serif;font-size:14px;color:#363A41;">'
            . '<strong style="font-size:15px;">'
            . '&#x1F3EA; ' . $this->l('Commande passée en magasin')
            . '</strong><br>'
            . '<span style="color:#0070bf;">' . $shopLabelEsc . '</span>'
            . '<br><br>'
            . $this->l('Votre commande a été enregistrée directement depuis notre borne en boutique.')
            . '</div>'
            . '</td></tr></table>';

        // Bloc HTML BO : fond orange, alerte visible pour le personnel
        // Inséré dans backoffice_order.html et new_order.html via {kgr_email_block_bo}
        $params['template_vars']['{kgr_email_block_bo}'] =
            '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 50px 20px;">'
            . '<div style="padding:15px 20px;background-color:#fff3cd;'
            . 'border-left:4px solid #e67e22;'
            . 'font-family:Open sans,Arial,sans-serif;font-size:14px;color:#363A41;">'
            . '<strong style="font-size:15px;color:#e67e22;">'
            . '&#x26A0;&#xFE0F; ' . $this->l('COMMANDE BORNE EN BOUTIQUE')
            . '</strong><br>'
            . '<strong>' . $shopLabelEsc . '</strong>'
            . '<br><br>'
            . $this->l('Cette commande a été passée depuis la borne en magasin.')
            . '</div>'
            . '</td></tr></table>';

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
