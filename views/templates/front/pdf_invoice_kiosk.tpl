{**
 * Template : bloc inséré dans le PDF de facture pour les commandes kiosque
 *
 * Compatible avec le hook displayPDFInvoice (PrestaShop 1.7.7+ / 8.x / 9.x)
 *
 * @author    Cyrille Mohr - Digital Food System
 * @copyright 2024 Digital Food System
 *}
<table width="100%" style="margin-bottom:10px;">
    <tr>
        <td style="background-color:#f5f5f5; border:1px solid #e0e0e0; padding:6px 10px; font-family:DejaVu Sans, sans-serif; font-size:10px; color:#333;">
            <strong style="color:#555;">{l s='Mode de commande :' mod='kioskguestreset'}</strong>
            &nbsp;{$kgr_pdf_label|escape:'html':'UTF-8'}
        </td>
    </tr>
</table>
