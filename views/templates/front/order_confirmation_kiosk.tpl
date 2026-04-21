{**
 * Template : bloc inséré sur la page de confirmation de commande kiosque
 *
 * Ce bloc est injecté via hookDisplayOrderConfirmation.
 * Il affiche un compte à rebours avant la réinitialisation automatique.
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 *}

<div class="kgr-confirmation-banner" id="kgr-confirmation-banner">
    <div class="kgr-confirmation-inner">
        <div class="kgr-confirmation-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>

        <div class="kgr-confirmation-text">
            <strong class="kgr-confirmation-title">
                {l s='Votre commande a bien été enregistrée !' mod='kioskguestreset'}
            </strong>
            <p class="kgr-confirmation-subtitle">
                {l s='Mode de commande :' mod='kioskguestreset'}
                <strong>{$kgr_shop_label|escape:'html':'UTF-8'}</strong>
            </p>
        </div>

        <div class="kgr-countdown-block">
            <p class="kgr-countdown-text">
                {l s='La borne sera réinitialisée pour le prochain client dans' mod='kioskguestreset'}
            </p>
            <div class="kgr-countdown-circle">
                <svg class="kgr-countdown-svg" viewBox="0 0 60 60">
                    <circle class="kgr-countdown-bg" cx="30" cy="30" r="26"/>
                    <circle class="kgr-countdown-progress" id="kgr-countdown-progress" cx="30" cy="30" r="26"
                        stroke-dasharray="163.36"
                        stroke-dashoffset="0"/>
                </svg>
                <span class="kgr-countdown-number" id="kgr-countdown-number">{$kgr_redirect_delay}</span>
            </div>
            <p class="kgr-countdown-seconds">{l s='seconde(s)' mod='kioskguestreset'}</p>
        </div>

        <div class="kgr-progress-bar">
            <div class="kgr-progress-fill" id="kgr-progress-fill"></div>
        </div>

        <p class="kgr-confirmation-merci">
            {l s='Merci pour votre commande. La borne sera prête pour le prochain client.' mod='kioskguestreset'}
        </p>
    </div>
</div>

<script>
(function () {
    'use strict';

    var delay       = {$kgr_redirect_delay|intval};
    var redirectUrl = '{$kgr_redirect_url|escape:'javascript'}';
    var resetUrl    = '{$kgr_reset_url|escape:'javascript'}';
    var newCookie   = '{$kgr_new_cookie_value|escape:'javascript'}';
    var cookieDuration = {$kgr_cookie_duration|intval};

    var countEl    = document.getElementById('kgr-countdown-number');
    var progressEl = document.getElementById('kgr-countdown-progress');
    var fillEl     = document.getElementById('kgr-progress-fill');
    var circumference = 163.36;

    var remaining = delay;
    var startTime = Date.now();

    function updateUI() {
        if (countEl) countEl.textContent = remaining;
        if (progressEl) {
            var fraction = remaining / delay;
            progressEl.style.strokeDashoffset = circumference * (1 - fraction);
        }
        if (fillEl) {
            fillEl.style.width = ((1 - remaining / delay) * 100) + '%';
        }
    }

    function doReset() {
        // 1. Appel AJAX pour détruire la session côté serveur
        var xhr = new XMLHttpRequest();
        xhr.open('POST', resetUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onloadend = function () {
            // 2. Supprimer l'ancien cookie kiosque
            document.cookie = 'kgr_kiosk_active=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Strict';

            // 3. Reposer un nouveau cookie kiosque pour le prochain client
            var expires = new Date(Date.now() + cookieDuration * 1000).toUTCString();
            document.cookie = 'kgr_kiosk_active=' + newCookie
                + '; expires=' + expires
                + '; path=/; SameSite=Strict';

            // 4. Rediriger vers la page d'accueil (ou URL configurée)
            window.location.href = redirectUrl;
        };
        xhr.send('');
    }

    var interval = setInterval(function () {
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        remaining = Math.max(0, delay - elapsed);
        updateUI();
        if (remaining <= 0) {
            clearInterval(interval);
            doReset();
        }
    }, 250);

    updateUI();
})();
</script>
