{**
 * Template Smarty : page d'activation PIN du mode kiosque
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 *}

{extends file='page.tpl'}

{block name='head_seo'}
    <title>{l s='Activation Borne' mod='kioskguestreset'}{if isset($shop) && isset($shop.name)} – {$shop.name}{/if}</title>
    <meta name="robots" content="noindex, nofollow">
{/block}

{block name='page_content'}
    {* Inclusion CSS du module *}
    <link rel="stylesheet" href="{$module_dir}views/css/kiosk.css">

    <div class="kgr-overlay">
        <div class="kgr-card">

            <div class="kgr-logo">
                {if isset($shop) && $shop.logo}
                    <img src="{$shop.logo}" alt="{$shop.name}" class="kgr-shop-logo">
                {elseif isset($shop)}
                    <span class="kgr-shop-name">{$shop.name}</span>
                {/if}
            </div>

            <div class="kgr-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </div>

            <h1 class="kgr-title">{l s='Borne Commande' mod='kioskguestreset'}</h1>
            <p class="kgr-subtitle">{l s='Saisissez le code PIN pour activer le mode kiosque.' mod='kioskguestreset'}</p>

            {if $kgr_error}
                <div class="kgr-alert kgr-alert-error" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {$kgr_error|escape:'html':'UTF-8'}
                </div>
            {/if}

            {if $kgr_kiosk_active}
                <div class="kgr-alert kgr-alert-info" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    {l s='Le mode kiosque est déjà actif.' mod='kioskguestreset'}
                </div>
            {/if}

            <form method="post" action="" class="kgr-form" autocomplete="off">
                <input type="hidden" name="submitPIN" value="1">

                <div class="kgr-pin-container">
                    <input
                        type="password"
                        name="kgr_pin"
                        id="kgr_pin"
                        class="kgr-pin-input"
                        maxlength="4"
                        minlength="4"
                        pattern="\d{literal}{4}{/literal}"
                        inputmode="numeric"
                        placeholder="••••"
                        aria-label="{l s='Code PIN 4 chiffres' mod='kioskguestreset'}"
                        required
                        autofocus
                    >
                </div>

                {* Numpad visuel pour tablette *}
                <div class="kgr-numpad" role="group" aria-label="{l s='Clavier numérique' mod='kioskguestreset'}">
                    {for $i=1 to 9}
                        <button type="button" class="kgr-numpad-btn" data-digit="{$i}" aria-label="{$i}">{$i}</button>
                    {/for}
                    <button type="button" class="kgr-numpad-btn kgr-numpad-clear" data-action="clear" aria-label="{l s='Effacer' mod='kioskguestreset'}">⌫</button>
                    <button type="button" class="kgr-numpad-btn" data-digit="0" aria-label="0">0</button>
                    <button type="button" class="kgr-numpad-btn kgr-numpad-submit" data-action="submit" aria-label="{l s='Valider' mod='kioskguestreset'}">✓</button>
                </div>

                <button type="submit" class="kgr-submit-btn" id="kgr-submit-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    {l s='Activer le mode kiosque' mod='kioskguestreset'}
                </button>
            </form>

        </div>

        <p class="kgr-footer-credit">{l s='Propulsé par' mod='kioskguestreset'} <strong>{if isset($shop)}{$shop.name}{/if}</strong></p>
    </div>

    <script>
    (function () {
        'use strict';
        var input  = document.getElementById('kgr_pin');
        var form   = document.querySelector('.kgr-form');
        var numpad = document.querySelector('.kgr-numpad');

        if (!input || !numpad) return;

        numpad.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-digit], [data-action]');
            if (!btn) return;

            var digit  = btn.getAttribute('data-digit');
            var action = btn.getAttribute('data-action');

            if (digit !== null) {
                if (input.value.length < 4) {
                    input.value += digit;
                }
            } else if (action === 'clear') {
                input.value = input.value.slice(0, -1);
            } else if (action === 'submit') {
                if (input.value.length === 4) {
                    form.submit();
                }
            }

            btn.classList.add('kgr-numpad-btn--pressed');
            setTimeout(function () {
                btn.classList.remove('kgr-numpad-btn--pressed');
            }, 150);
        });

        input.addEventListener('input', function () {
            if (input.value.length === 4) {
                setTimeout(function () { form.submit(); }, 300);
            }
        });
    })();
    </script>
{/block}

