# Kiosque Borne Magasin — `kioskguestreset`

**Auteur :** Cyrille Mohr – Digital Food System  
**Version :** 1.0.0  
**Compatibilité :** PrestaShop 8.x et 9.x  
**Dépôt :** `github.com/Cyrmo/DFS-Kiosk`

> Transforme une boutique PrestaShop en borne de commande autonome pour tablettes en magasin. Activation sécurisée par PIN, nettoyage automatique après chaque commande.

---

## Sommaire

1. [Fonctionnalités](#fonctionnalités)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Utilisation quotidienne](#utilisation-quotidienne)
5. [Commandes kiosque dans le Back-Office](#commandes-kiosque-dans-le-back-office)
6. [Personnalisation des emails](#personnalisation-des-emails)
7. [Factures PDF](#factures-pdf)
8. [Architecture technique](#architecture-technique)
9. [Sécurité](#sécurité)
10. [Limites et points d'attention](#limites-et-points-dattention)

---

## Fonctionnalités

| Fonctionnalité | Description |
|---|---|
| 🔐 **Activation PIN** | Accès borne via un code PIN à 4 chiffres |
| 🛡️ **Anti-brute-force** | Blocage automatique IP après 5 tentatives (10 min) |
| 🍪 **Cookie sécurisé** | Signé HMAC-SHA256, expirant automatiquement |
| 🔄 **Reset automatique** | Nettoyage panier + session après chaque commande |
| 📋 **Note interne BO** | Chaque commande kiosque est marquée et identifiable |
| 📧 **Variables email** | Variables Smarty pour personnaliser les emails de confirmation |
| 🧾 **Mention PDF** | Ligne "Mode de commande : Borne" sur les factures |
| ⚙️ **Entièrement configurable** | Durée de session, délai de reset, URL de retour, label boutique |

---

## Installation

1. Copier le dossier `kioskguestreset` dans `modules/` de votre PrestaShop
2. Dans le Back-Office : **Modules > Gestionnaire de modules**
3. Rechercher **Kiosque Borne Magasin** et cliquer sur **Installer**

L'installation crée automatiquement :
- Une table SQL `kgr_pin_attempts` (protection brute-force)
- Une clé secrète cryptographique unique à la boutique

---

## Configuration

**Chemin :** Back-Office → Modules → Kiosque Borne Magasin → **Configurer**

### Paramètres disponibles

#### 🔑 PIN (4 chiffres)
Code d'activation de la borne. Stocké uniquement sous forme de hash SHA-256 — jamais en clair.  
> ⚠️ **Aucun PIN n'est défini à l'installation.** La borne est inaccessible tant qu'un PIN n'est pas configuré.  
> Laisser le champ vide pour conserver le PIN existant.

#### 🏷️ Label de la boutique
Nom affiché pour identifier la borne (ex : `Borne en boutique – Strasbourg`).  
Apparaît dans les notes de commande, emails et factures PDF.  
**Défaut :** `Borne en boutique`

#### 🌐 URL de redirection
URL vers laquelle la tablette est renvoyée après :
- L'activation réussie du PIN
- La fin de chaque commande (après le compte à rebours)

**Défaut :** `/`  
> ⚠️ Sur une installation PrestaShop en sous-dossier (ex : `domain.fr/boutique/`), configurer cette URL à `/boutique/`.

#### ⏱️ Durée du cookie kiosque
Combien de temps la tablette reste en mode kiosque sans ressaisir le PIN.

| Valeur | Durée |
|---|---|
| `3600` | 1 heure |
| `7200` | 2 heures |
| `14400` | 4 heures |
| `28800` | 8 heures |
| `43200` | 12 heures |
| `86400` | **24 heures (défaut)** |
| `172800` | 48 heures |
| `604800` | 7 jours |

> 💡 En cas de vol ou perte de la tablette, le mode kiosque expire automatiquement.

#### ⏳ Délai avant réinitialisation
Secondes affichées sur le compte à rebours après confirmation de commande.  
Valeurs : 3s, **5s (défaut)**, 10s, 15s, 30s.

---

## Utilisation quotidienne

### Activer la borne (responsable)

1. Sur la tablette, aller à l'URL affichée dans le BO (section "Configurer") :
   ```
   https://votre-boutique.fr/module/kioskguestreset/borne
   ```
2. Saisir le PIN à 4 chiffres sur le clavier numérique affiché
3. Cliquer **Activer le mode kiosque**
4. La tablette est redirigée vers la boutique — la borne est opérationnelle

### Ce que voit un client

- La boutique PrestaShop s'affiche normalement
- Le client peut commander en tant qu'**invité**
- Après validation de la commande, un **bloc de confirmation kiosque** s'affiche :
  - "Votre commande a bien été enregistrée !"
  - "Mode de commande : [Label boutique]"
  - Compte à rebours avant remise à zéro
- À la fin du compte à rebours, la session est nettoyée et la tablette revient à l'accueil

### Protection anti-brute-force

- Après **5 tentatives PIN incorrectes**, l'IP est bloquée pendant **10 minutes**
- Un avertissement s'affiche dès qu'il reste ≤ 2 tentatives
- Le compteur se remet à zéro automatiquement après le délai

---

## Commandes kiosque dans le Back-Office

### Note interne automatique

Chaque commande passée via la borne reçoit automatiquement :

1. **Un champ note sur la commande** :
   ```
   Commande via borne – Borne en boutique – 21/04/2026 14:03
   ```

2. **Un message privé** visible dans l'onglet **Messages** de la commande BO

### Retrouver les commandes kiosque

1. Commandes → Commandes
2. Ouvrir la commande concernée
3. Section **Messages** (colonne droite) → message privé "Commande via borne"

---

## Personnalisation des emails

Le module injecte des variables Smarty dans tous les emails PrestaShop.

### Variables disponibles

| Variable | Valeur |
|---|---|
| `{kgr_is_borne}` | `1` si commande kiosque, `0` sinon |
| `{kgr_order_origin}` | Label boutique (ex : `Borne en boutique – Strasbourg`) |
| `{kgr_shop_name}` | Nom de la boutique PrestaShop |

### Exemple dans `order_conf.html`

```html
{if $kgr_is_borne == '1'}
<p style="background:#f0f7ff; padding:10px; border-left:4px solid #0070bf;">
  🏪 <strong>Commande passée en magasin</strong><br>
  Mode de commande : {$kgr_order_origin}
</p>
{/if}
```

> ⚠️ Les templates email ne sont **pas modifiés automatiquement**. Il faut éditer manuellement les fichiers dans `mails/fr/`.

---

## Factures PDF

Les factures des commandes kiosque affichent automatiquement une ligne supplémentaire :

```
Mode de commande : Borne en boutique
```

Visible sur les PDF générés depuis le BO (Commandes → icône PDF).

---

## Architecture technique

### Structure des fichiers

```
kioskguestreset/
├── kioskguestreset.php                      → Cerveau du module (hooks, config BO)
├── controllers/front/
│   ├── borne.php                            → Page PIN (GET + POST, brute-force)
│   └── reset.php                            → Endpoint AJAX de nettoyage session
└── views/
    ├── css/kiosk.css                        → Styles page PIN
    └── templates/front/
        ├── borne.tpl                        → Interface clavier PIN
        ├── order_confirmation_kiosk.tpl     → Bloc confirmation + compte à rebours
        └── pdf_invoice_kiosk.tpl           → Mention "Borne" sur PDF
```

### Table SQL créée à l'installation

```sql
CREATE TABLE kgr_pin_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    id_shop      INT UNSIGNED NOT NULL DEFAULT 1,
    attempts     TINYINT NOT NULL DEFAULT 0,
    last_attempt DATETIME NOT NULL,
    UNIQUE KEY ip_shop (ip, id_shop)
)
```

### Hooks enregistrés

| Hook | Rôle |
|---|---|
| `displayHeader` | Charge le CSS kiosque + synchronise l'état en session PHP |
| `actionValidateOrder` | Écrit la note interne au moment de la validation commande |
| `displayOrderConfirmation` | Affiche le bloc de confirmation + écrit la note (fallback fiable) |
| `sendMailAlterTemplateVars` | Injecte les variables `{kgr_*}` dans les emails |
| `displayPDFInvoice` | Ajoute la ligne "Mode de commande" sur la facture PDF |

### Cycle de vie d'une session kiosque

```
Responsable → URL /borne → saisie PIN
           → Vérification CSRF + brute-force + hash PIN
           → Cookie HMAC-SHA256 posé (id_shop | timestamp | signature)
           → Redirection boutique

Chaque page → hookDisplayHeader() → isKioskModeActive()
           → Validation cookie (shop_id + expiration + HMAC)
           → Propagation état en $_SESSION (compatibilité PS9/Symfony)

Commande validée → hookDisplayOrderConfirmation()
               → Note interne écrite (ps_orders.note + ps_message privé)
               → Bloc confirmation affiché + compte à rebours

Fin du délai → AJAX POST /reset
            → Logout + delete panier + clean cookie PS + destroy session
            → Cookie kiosque renouvelé
            → Redirection accueil → prêt pour le client suivant ♻️
```

---

## Sécurité

| Mécanisme | Détail |
|---|---|
| **PIN hashé** | SHA-256 salé avec l'ID boutique (`hash('sha256', $pin . '_kgr_' . $id_shop)`) |
| **Cookie HMAC-SHA256** | Contient : `base64(id_shop \| timestamp \| HMAC)` — vérifié à chaque requête |
| **Expiration serveur** | Le timestamp du cookie est vérifié côté PHP, indépendamment du navigateur |
| **Clé secrète immuable** | Générée une seule fois à l'installation (`random_bytes(32)`), jamais régénérée |
| **Anti-brute-force** | 5 tentatives max / IP / boutique, blocage 10 min, stocké en BDD |
| **CSRF (formulaire PIN)** | Token session PHP usage unique, compatible PS8 et PS9 |
| **Endpoint reset** | Accessible uniquement en POST AJAX avec cookie kiosque valide |
| **SameSite=Strict** | Cookie posé avec cette option pour bloquer les requêtes cross-site |
| **Compat. reverse proxy** | Lit `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR` pour la vraie IP |

---

## Limites et points d'attention

### ⚠️ URL de redirection
La valeur par défaut (`/`) renvoie vers la racine du serveur, pas forcément vers la boutique. À corriger si PrestaShop est installé dans un sous-dossier.

### ⚠️ Pas de bouton "désactiver la borne"
Pour quitter le mode kiosque avant l'expiration naturelle du cookie :
- Supprimer manuellement le cookie `kgr_kiosk_active` dans le navigateur
- Ou changer la `KGR_SECRET_KEY` en BDD (invalide toutes les tablettes actives)

### ⚠️ Templates email non modifiés automatiquement
Les variables `{kgr_is_borne}` etc. sont injectées mais les fichiers HTML des emails doivent être modifiés manuellement.

### ⚠️ Multi-boutique
Fonctionne en mono-boutique. En multi-boutique avec configurations différentes par boutique, une vérification approfondie est recommandée.

### ℹ️ Connexion réseau requise
Pas de mode hors-ligne — le module ne cache pas les produits localement.

### ℹ️ Commandes invitées recommandées
Le module ne force pas le mode invité. En production, il est conseillé de désactiver la création de compte ou la connexion si la boutique sert exclusivement de borne.

---

## Support

**Cyrille Mohr – Digital Food System**  
Email : cyrille.mohr@gmail.com
