# Module KioskGuestReset – Documentation d'installation et d'utilisation

**Auteur :** Cyrille Mohr - Digital Food System  
**Version :** 1.0.0  
**Compatibilité :** PrestaShop 8.x et 9.x  
**Multi-boutique :** ✅ Oui

---

## Description

Le module **KioskGuestReset** transforme votre boutique PrestaShop en borne de commande autonome sur tablette. Il ajoute un mode kiosque sécurisé par PIN sans modifier le tunnel de commande natif de PrestaShop.

**Fonctionnalités :**
- Activation du mode kiosque via une URL dédiée + PIN à 4 chiffres
- Clavier numérique visuel optimisé tablette
- Protection anti-brute-force (5 tentatives max / 10 min)
- Cookie sécurisé signé HMAC-SHA256
- Nettoyage automatique de la session après chaque commande
- Compte à rebours visuel avant réinitialisation
- Identification des commandes kiosque (note interne, email, PDF)
- Configuration indépendante par boutique (multi-shop)

---

## Installation

1. Copier le dossier `kioskguestreset` dans `modules/` de votre PrestaShop
2. Dans le back-office : **Modules > Gestionnaire de modules**
3. Rechercher **Kiosque Borne Magasin** et cliquer sur **Installer**

---

## Configuration

**Back-office > Modules > Kiosque Borne Magasin > Configurer**

| Paramètre | Description |
|-----------|-------------|
| PIN (4 chiffres) | Code d'activation de la borne, hashé en SHA-256 |
| Label de la boutique | Ex: `Borne en boutique – Strasbourg` |
| URL de redirection | Page vers laquelle la borne revient après activation et après commande |
| Durée du cookie | De 1h à 7 jours (défaut : 24h) |
| Délai avant réinitialisation | Secondes affichées après confirmation (défaut : 5s) |

> **Important :** En multi-boutique, configurer chaque boutique séparément via le menu déroulant boutique du back-office.

---

## Utilisation sur la tablette

1. Ouvrir l'URL : `https://votre-boutique.fr/module/kioskguestreset/borne`
2. Saisir le PIN à 4 chiffres (clavier numérique ou clavier physique)
3. La tablette est redirigée vers la boutique en mode kiosque
4. Le client navigue et passe commande normalement (mode invité)
5. Après la confirmation de commande, un compte à rebours s'affiche
6. La session est automatiquement nettoyée et la borne est prête pour le client suivant

---

## Variables email (templates)

Pour afficher l'origine kiosque dans les emails de confirmation, modifier le template `order_conf.html` dans :

**Back-office > Paramètres avancés > Emails > Traduire les emails**

Ajouter dans le corps de l'email :

```html
{if $kgr_is_borne == '1'}
<p style="color:#555; font-size:13px;">
    <strong>Mode de commande :</strong> {$kgr_order_origin}
</p>
{/if}
```

Variables disponibles :

| Variable | Valeur |
|----------|--------|
| `{kgr_is_borne}` | `1` si commande kiosque, `0` sinon |
| `{kgr_order_origin}` | Ex: `Borne en boutique – Strasbourg` |
| `{kgr_shop_name}` | Nom de la boutique PrestaShop |

---

## Identification des commandes kiosque

Les commandes passées via la borne sont automatiquement identifiées par :

- **Note interne de la commande** (back-office > Commandes) :  
  `Commande via borne – Borne en boutique Strasbourg – 16/03/2024 14:35`
- **PDF de facture** : ligne "Mode de commande : Borne en boutique – Strasbourg"
- **Email de confirmation** : si le template a été modifié (voir ci-dessus)

---

## Sécurité

| Menace | Protection |
|--------|------------|
| Brute-force PIN | Blocage 10 min après 5 tentatives incorrectes |
| Cookie forgé | Signature HMAC-SHA256 avec clé secrète unique par installation |
| Accès AJAX non autorisé | Validation cookie + token CSRF |
| PIN en clair | Non stocké – uniquement son hash SHA-256 |

---

## Structure des fichiers

```
modules/kioskguestreset/
├── kioskguestreset.php               # Fichier principal
├── config.xml                        # Métadonnées
├── controllers/front/
│   ├── borne.php                     # Page activation PIN
│   └── reset.php                     # Endpoint AJAX reset session
├── views/
│   ├── templates/front/
│   │   ├── borne.tpl                 # Template page PIN
│   │   ├── order_confirmation_kiosk.tpl  # Bloc confirmation + countdown
│   │   └── pdf_invoice_kiosk.tpl    # Bloc PDF facture
│   └── css/
│       └── kiosk.css                 # Styles kiosque
└── sql/
    ├── install.sql
    └── uninstall.sql
```

---

## Support

**Cyrille Mohr – Digital Food System**
