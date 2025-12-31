# Votre boutique en ligne — Mode d’emploi 


---

## Plan

### Côté commerçant (ce que vous faites vraiment)
1. [Se connecter à l’admin](#1-se-connecter-à-ladmin)
2. [Mettre un produit en ligne](#2-mettre-un-produit-en-ligne)
3. [Gérer prix & stock](#3-gérer-prix--stock)
4. [Ajouter de belles images](#4-ajouter-de-belles-images)
5. [Suivre et traiter les commandes](#5-suivre-et-traiter-les-commandes)
6. [Créer une promo / un coupon](#6-créer-une-promo--un-coupon)
7. [Mettre la boutique en maintenance](#7-mettre-la-boutique-en-maintenance)
8. [L’assistant IA EasyAdmin (la fiche produit en quelques secondes)](#8-lassistant-ia-easyadmin-la-fiche-produit-en-quelques-secondes)
9. [FAQ express](#9-faq-express)

### Côté technique (installation & exploitation)
10. [Stack & prérequis](#10-stack--prérequis)
11. [Installation locale](#11-installation-locale)
12. [Installation Docker](#12-installation-docker)
13. [Configuration (.env / secrets)](#13-configuration-env--secrets)
14. [Base de données : migrations & fixtures](#14-base-de-données--migrations--fixtures)
15. [Accès admin & sécurité](#15-accès-admin--sécurité)
16. [Structure MVC : où mettre quoi](#16-structure-mvc--où-mettre-quoi)
17. [Stripe : fonctionnement & bonnes pratiques](#17-stripe-fonctionnement--bonnes-pratiques)
18. [IA (OpenAI) : activer, cadrer, maîtriser les coûts](#18-ia-openai-activer-cadrer-maîtriser-les-coûts)
19. [Qualité / sécurité / exploitation](#19-qualité--sécurité--exploitation)
20. [Dépannage](#20-dépannage)
21. [Déploiement](#21-déploiement)


---

# Côté commerçant

## 1) Se connecter à l’admin

- Ouvrez : **`/admin`**
- Connectez‑vous avec votre compte **administrateur**

🔐 Bon réflexe : évitez de partager un compte admin. Idéalement, **un compte par personne**.

---

## 2) Mettre un produit en ligne

Dans cette boutique, on pense comme ça :

- **Produit** : la fiche (nom, description, catégorie, marque…)
- **Variante** : la déclinaison vendable (couleur / taille / prix / stock)
- **Images** : les photos (une principale + des secondaires)

### Le chemin le plus simple (en 2 minutes)
1. Admin → **Produits** → **Créer**
2. Remplissez le minimum :
   - Nom
   - Catégorie
   - Description (même courte au départ)
3. Ajoutez **au moins une variante** :
   - prix
   - stock
   - (couleur / taille si votre produit en a)
4. Ajoutez 1–3 images
5. **Enregistrer**

✅ Si le produit a **une variante** avec **prix + stock**, il peut être vendu.

---

## 3) Gérer prix & stock

Dans la plupart des cas, le **prix** et le **stock** sont sur la **variante** (ex : “Noir / M”).  
C’est logique : une taille peut être en rupture, une autre non.

✅ Conseils terrain :
- mettez le stock à jour **dès que ça bouge**
- après un changement de prix, faites un petit check côté boutique (fiche produit)

---

## 4) Ajouter de belles images

Des images propres = des ventes plus faciles.

📸 Recommandations :
- format : **JPG/PNG**
- largeur : **1000px** minimum
- 3 angles : face / détail / contexte
- fond neutre si possible

Admin → Produit → **Images** → ajouter → **Enregistrer**

---

## 5) Suivre et traiter les commandes

Admin → **Commandes**

Vous y retrouvez :
- le client
- les articles
- le total
- le statut (selon votre configuration)

🧭 Déroulé conseillé :
1. vérifier (adresse, quantité, cohérence)
2. préparer
3. expédier
4. mettre à jour le statut si disponible

> Si le paiement est en ligne (Stripe), la confirmation “vraie” vient idéalement du serveur via webhook (détails en partie technique).

---

## 6) Créer une promo / un coupon

Admin → **Coupons** → **Créer**

Selon votre réglage, vous choisissez :
- réduction en **%**
- ou réduction en **montant fixe**
- produit(s) concernés
- dates de validité (si activé)
- actif / inactif

✅ Après enregistrement : allez voir la page produit côté boutique pour vérifier l’affichage.

---

## 7) Mettre la boutique en maintenance

Pratique quand vous faites une mise à jour, ou un gros nettoyage de catalogue.

Admin → **Paramètres** → activer **Maintenance** → **Enregistrer**

Effet :
- les visiteurs voient une page “maintenance”
- l’admin reste accessible (vous continuez à travailler)

---

## 8) L’assistant IA EasyAdmin (la fiche produit en quelques secondes)

L’administration inclut un **Assistant IA directement intégré à EasyAdmin**, pensé pour gagner un temps énorme sur la création de fiches produits. Au lieu de remplir tout à la main, vous pouvez demander à l’assistant de générer une fiche complète **en quelques secondes** : un **titre clair et vendeur**, une **description structurée** (avec un ton adapté à votre boutique), des **points forts**, des **caractéristiques**, des **mots‑clés**, et même des **suggestions de variantes** (ex : tailles/couleurs) si votre produit s’y prête. L’assistant peut également **proposer et importer des images** pertinentes via le workflow d’images, ce qui permet de publier une fiche “prête à vendre” très rapidement.  
. L’IA est donc un accélérateur : elle vous fait gagner du temps, mais **vous gardez toujours la main** sur le résultat final.

---

## 9) FAQ express

**Je ne vois pas mon produit sur le site**  
- A‑t‑il au moins **une variante** avec **prix + stock** ?
- A‑t‑il une **catégorie** ?
- A‑t‑il été **enregistré** ?

**Le paiement ne marche pas**  
- Stripe est‑il activé ?
- Contactez le support technique (clés / webhooks)

**Je n’arrive plus à me connecter**  
- utilisez “mot de passe oublié” si disponible
- sinon contactez l’admin technique

---

# Côté technique

## 10) Stack & prérequis

- **Symfony 6.x** (cible 6.1)
- **PHP** >= 8.0.2 (recommandé 8.2)
- **Composer**
- DB : MySQL 8+ ou PostgreSQL 13+ (selon `DATABASE_URL`)

---

## 11) Installation locale

```bash
composer install
cp .env .env.local
```

Configurer `DATABASE_URL` dans `.env.local`, puis :

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load -n
```

Démarrer :
```bash
symfony server:start
# ou
php -S 127.0.0.1:8000 -t public
```

---

## 12) Installation Docker

```bash
docker compose up -d --build
```

> Note : si le compose fournit Postgres mais que le projet vise MySQL (ou l’inverse), adapter le service DB **ou** `DATABASE_URL`.

---

## 13) Configuration (.env / secrets)

### Variables essentielles
- `APP_ENV` (`dev` / `prod`)
- `APP_SECRET` (prod : fort, hors git)
- `APP_HOSTNAME` (base URL pour emails/SEO)
- `DATABASE_URL`
- `MAILER_DSN`

### Stripe (si utilisé)
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_CURRENCY` (ex: `eur`)

### IA (si utilisée)
- `OPENAI_API_KEY`
- `OPENAI_MODEL`
- options : langue / ton / domaines autorisés (selon implémentation)

---

## 14) Base de données — migrations & fixtures

Migrations :
```bash
php bin/console doctrine:migrations:migrate -n
```

Fixtures :
```bash
php bin/console doctrine:fixtures:load -n
```

> Si le modèle est “variant‑first”, vérifier que les fixtures créent des **variantes** avec **prix+stock**.

---

## 15) Accès admin — sécurité

Objectifs :
- au moins un compte `ROLE_ADMIN`
- mots de passe forts en prod
- traçabilité (qui fait quoi) si possible

Selon le projet, un outil peut exister :
```bash
php bin/console app:create-admin
```

---

## 16) Structure MVC — où mettre quoi

```
src/
  Controller/       # MVC: C
  Entity/           # MVC: M (Doctrine)
  Repository/
  Service/          # logique métier (checkout, paiement, IA, import…)
  Form/
templates/          # MVC: V (Twig)
public/             # assets + uploads
migrations/
```

Exemples rapides :
- nouvelle page : `Controller` + `templates`
- nouveau champ DB : `Entity` + migration
- nouveau use‑case : `Service` + tests

---

## 17) Stripe — fonctionnement & bonnes pratiques

- La redirection “success” ne suffit pas : finaliser via **webhook signé** (`STRIPE_WEBHOOK_SECRET`).
- Idempotence : éviter la double création de commande (session key/lock/unique).
- Journaliser : création checkout, réception webhook, transition statut.

---

## 18) IA (OpenAI) — activer, cadrer, maîtriser les coûts

Présence fonctionnelle IA :
- `src/Service/Ai/*`
- contrôleurs admin AI/GPT
- templates admin dédiés

Recommandations :
- garde‑fous : rate limit / quotas / coût
- timeouts, size caps, validation MIME sur imports
- logs : action, user, prompt type, coût estimé

---

## 19) Qualité / sécurité / exploitation

Minimum recommandé :
- actions mutatives : **POST/DELETE + CSRF**
- éviter `|raw` sur storefront (XSS)
- logs : commandes/paiements/imports
- tests : au moins parcours panier → commande
- CI : PHPStan + CS Fixer

---

## 20) Dépannage

**DB**
- vérifier `DATABASE_URL`
- vérifier le service DB (docker/local)

**Admin inaccessible**
- vérifier `ROLE_ADMIN`
- reset password via outil interne si dispo

**Fixtures**
- charger un set minimal puis enrichir
- vérifier contraintes (FK, stock, variantes)

---

## 21) Déploiement

- `APP_ENV=prod`
- secrets via l’hébergeur (pas dans `.env`)
- DB managée + sauvegardes
- HTTPS obligatoire
- monitoring (logs + erreurs)
- cron (si tâches : nettoyage tokens, emails, etc.)

---

