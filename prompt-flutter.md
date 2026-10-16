# PROMPT — Application Flutter Cahier Bi (propriétaire et gérant) : mobile et PWA

Agis comme un architecte senior Flutter/Dart, spécialisé en Clean Architecture et en PWA.

Crée un **vrai projet Flutter fonctionnel** pour **Cahier Bi**, un SaaS de suivi des dettes clients pour les boutiques au Sénégal. Place-le dans `Dette-saas/mobile/`, à côté de `backend/` (Laravel) et de `project/` (Angular, réservé à l'admin).

**Périmètre :**
- **Utilisateurs** : uniquement le **PROPRIETAIRE** et le **GERANT**. L'espace admin de la plateforme existe déjà en Angular : ne le refais pas.
- **Cibles** : Android, iOS et Web sous forme de **PWA installable**.
- **Données** : ce lot n'utilise que des **données statiques**. Aucun appel HTTP, aucun backend, aucune authentification réelle.
- **Contrat** : les modèles et les repositories doivent suivre exactement le contrat de l'API Laravel (`/api/manager/*` et `/api/auth/*`). Plus tard, on remplacera la datasource statique par une datasource HTTP (Dio). On ne touchera alors ni au domaine ni aux écrans.

Ne t'arrête pas à l'arborescence ou à des extraits : crée les fichiers, implémente, exécute et vérifie.

---

## 1. Contexte métier

- **Tenant** : le compte du propriétaire. Il détient l'abonnement, avec un essai gratuit de 14 jours à l'inscription.
- **Boutique** : appartient à un tenant. Elle est créée **uniquement par l'admin**, jamais depuis l'app. Statut : `actif | inactif | suspendu`.
- **Rôles** :
  - `PROPRIETAIRE` : possède une ou plusieurs boutiques et peut basculer de l'une à l'autre.
  - `GERANT` : rattaché à **une seule** boutique (`boutique_id`), sur laquelle il est fixé.
- **Quota de gérants par boutique** : il dépend du forfait (`max_gerants_par_boutique`).
  - `gratuit` = 1, `boutique` = 3, `boutique_pro` = 10, `annuel` = illimité (`null`).
  - Le quota bloque l'ajout d'un gérant **et** le transfert vers une boutique pleine. On ne supprime et ne désactive jamais rien automatiquement.
  - Un transfert n'est possible que vers une autre boutique **du même propriétaire**.
- **Client** : appartient à une boutique.
- **Dette** : chaque nouvel achat crée une nouvelle dette.
  - Champs : `montant`, `montant_paye`, `montant_restant`, `date_creation`, `date_echeance`.
  - Statut : `en_attente | a_aujourdhui | en_retard | payee | annulee`. Il est calculé à partir de `date_echeance` par rapport à aujourd'hui et du montant restant.
- **Paiement** : il se fait de deux façons.
  - Sur une dette précise, avec un montant ≤ `montant_restant`.
  - Par **versement global** du client : le montant est réparti de la dette la plus ancienne à la plus récente. Les lignes `paiements` créées partagent le même `versement` (uuid).
  - Méthodes : `especes | wave | orange_money | free_money | autre`.
- **Annulations réservées au propriétaire** : annuler une dette, ou annuler un versement entier (toutes ses lignes).
- **Rappels** :
  - canal : `whatsapp | sms` ;
  - ton : `poli | normal | ferme` ;
  - statut : `en_attente | envoye | echoue`.
- **Abonnement expiré ou compte désactivé** : l'accès à l'espace est coupé (équivalent du middleware `subscription` et du code `SUBSCRIPTION_EXPIRED`). Pour un gérant, l'accès est aussi coupé si sa boutique est désactivée.
- **Formats** :
  - montants en FCFA (XOF), sans décimales, avec une espace comme séparateur de milliers (`125 000 FCFA`) ;
  - téléphones sénégalais affichés sous la forme `+221 77 123 45 67` ;
  - dates en français (`intl`, locale `fr_FR`) ;
  - toute l'interface est en français.

---

## 2. Contrat de l'API à reproduire

**Enveloppe des réponses** à modéliser dans la couche Data :
- Succès : `{ status, message, payload }`.
- Liste paginée : `{ status, message, payload: [...], meta: { current_page, per_page, total, last_page } }`.
- Erreur : `{ status, message, error_code?, errors? }`.
  - Les erreurs de validation 422 doivent s'afficher sous les champs concernés.
  - Les `error_code` du backend doivent être reproduits par la datasource fake et traduits en messages clairs :
    - **accès** : `SUBSCRIPTION_EXPIRED`, `ACCOUNT_DISABLED`, `TENANT_DISABLED`, `BOUTIQUE_DISABLED`, `BOUTIQUE_INACTIVE`, `INVALID_CREDENTIALS`, `TOKEN_INVALID`, `FORBIDDEN` ;
    - **OTP** : `OTP_INVALID`, `OTP_EXPIRED`, `OTP_TOO_MANY_ATTEMPTS`, `OTP_RESEND_TOO_SOON`, `OTP_RESEND_LIMIT` ;
    - **mot de passe** : `RESET_CODE_INVALID`, `RESET_CODE_EXPIRED`, `CURRENT_PASSWORD_INVALID` ;
    - **dettes et paiements** : `MONTANT_SUPERIEUR_AU_RESTE`, `MONTANT_INFERIEUR_AU_PAYE`, `DETTE_CLOSE`, `DETTE_HAS_PAYMENTS`, `AUCUNE_DETTE`, `CLIENT_HAS_DEBT`, `VERSEMENT_NOT_FOUND` ;
    - **gérants** : `GERANTS_LIMIT_REACHED`, `TRANSFER_OTHER_OWNER`, `TRANSFER_SAME_BOUTIQUE`.

    Avant de les écrire, vérifie dans `backend/app` dans quels cas chaque code est renvoyé.

**Modèles** (champs du backend en snake_case pour le JSON, en camelCase côté Dart) :
- `Boutique { id, tenant_id, nom, slug, telephone, whatsapp, adresse, quartier, ville, logo_path, statut }`
- `Client { id, boutique_id, nom, telephone, whatsapp, photo_path, adresse, note }`, avec les champs calculés `total_du` et `dettes_en_retard`.
- `Dette { id, boutique_id, client_id, reference, montant, montant_paye, montant_restant, date_creation, date_echeance, statut, note }`
- `Paiement { id, dette_id, boutique_id, client_id, enregistre_par, versement, montant, date_paiement, methode, reference, note }`
- `Rappel { id, dette_id, boutique_id, canal, ton, statut, envoye_le, message, erreur }`
- `Profil` (réponse de `GET /auth/me`) : `{ id, first_name, last_name, full_name, email, phone, phone_display, phone_two, address, role, tenant, boutique: {id, nom, ville, quartier}?, subscription }`
- `SubscriptionStatus { state, plan, starts_at, ends_at, days_left }`
- `SubscriptionPlan { id, name, duration_months, price, currency, max_gerants_par_boutique, description, is_active, position }`

Les enums Dart doivent correspondre **exactement** aux valeurs du backend, avec `fromJson`/`toJson`.

**Dashboard** (`GET /manager/dashboard?boutique_id=`) :

```
{ subscription, total_a_recuperer,
  dettes: { en_attente|a_aujourdhui|en_retard: { count, total } },
  encaisse_aujourdhui, encaisse_mois, clients_count, clients_debiteurs_count,
  a_relancer: [ { id, reference, client: {id, nom, telephone}, montant_restant, date_echeance, statut } ] }
```

**Endpoints à couvrir par les repositories** (une méthode par endpoint) :
- **Public** : `app-version`, `subscription-offers`.
- **Auth** : `login`, `register`, `otp/verify`, `otp/resend`, `password/set`, `forgot-password`, `reset-password`, `refresh`, `logout`, `me` (GET et PUT), `change-password`.
- **Manager** :
  - `boutiques` ;
  - `clients` : CRUD, ainsi que `clients/{id}/paiements` (versement global) et `clients/{id}/versements` ;
  - `dettes` : CRUD, ainsi que `dettes/{id}/paiements` et `dettes/{id}/annuler` ;
  - `versements/{uuid}` en DELETE ;
  - `company` (GET et POST) et `subscription` ;
  - `gerants` : CRUD, ainsi que `transfer`, `toggle-status` et `reset-access`.

Le code source de vérité est dans `backend/routes/api.php` et `backend/app/Http/Controllers/`. **Lis ces fichiers** avant d'écrire les modèles. En cas d'écart avec ce prompt, c'est le backend qui fait foi.

---

## 3. Stack

- Flutter stable et Dart 3, `very_good_analysis` ou `flutter_lints` en mode strict.
- **Riverpod** (avec `riverpod_generator` si tu l'utilises partout, sinon des providers manuels, mais pas un mélange des deux) pour l'état et l'injection.
- **GoRouter** pour la navigation, avec `redirect` selon la session, le rôle et l'état de l'abonnement.
- `freezed` et `json_serializable` pour les modèles et les états.
- `intl` pour les formats, `url_launcher` pour WhatsApp/SMS/appel.
- Un graphique simple : `fl_chart`.
- Material 3, thème centralisé, aucune autre librairie UI.

Avant d'ajouter un package, vérifie qu'il est compatible avec la version de Flutter installée **et** avec le Web. N'ajoute pas de dépendance que tu n'utilises pas.

---

## 4. Architecture (Clean Architecture, organisée par fonctionnalité)

```text
lib/
├── main.dart · bootstrap.dart
├── app/            app.dart, router/, theme/ (couleurs, typo, statuts), di/
├── core/           errors/ (Failure, ApiException + error_code), result/ (Result<T>),
│                   network/ (ApiEnvelope, Paginated<T>), utils/ (fcfa, téléphone, dates), platform/
├── shared/widgets/ composants réutilisables (voir §7)
└── features/
    ├── auth/  ├── dashboard/  ├── boutiques/  ├── clients/  ├── dettes/
    ├── paiements/  ├── rappels/  ├── gerants/  ├── abonnement/  └── profil/
        ├── domain/       entities/, repositories/ (abstraits), usecases/
        ├── data/         models/ (DTO + mapping vers l'entité), datasources/, repositories/ (impl.)
        └── presentation/ pages/, widgets/, providers/
test/  unit/  widget/  helpers/
```

**Règles :**
- Le **domaine** est en Dart pur : aucun import de Flutter, de Riverpod ou d'un package de données.
- La présentation passe toujours par un use case ou un repository injecté. Elle n'appelle **jamais** directement une datasource.
- Chaque feature déclare une datasource abstraite, par exemple `ClientRemoteDataSource`, avec une implémentation `FakeClientRemoteDataSource`. Celle-ci doit :
  - renvoyer les mêmes formes JSON que l'API (enveloppe et pagination) ;
  - simuler une latence de 300 à 600 ms ;
  - simuler une erreur, activable depuis un réglage de démo.

  Le jour du branchement, il suffira d'écrire `HttpClientRemoteDataSource` et de changer **un seul provider**.
- **Use cases** : crée-les seulement s'ils portent une règle métier, par exemple `RepartirVersementGlobal`, `CalculerStatutDette`, `VerifierQuotaGerants` ou `TransfererGerant`. Pour une simple lecture, le repository suffit.
- Pas de fichier géant, pas de duplication, pas d'abstraction inutile.

---

## 5. Écrans

**Démarrage et authentification**
- **Vérification de version** (`app-version`) :
  - mise à jour proposée si une version plus récente existe ;
  - mise à jour imposée si la version est inférieure à `min_version` (simulé).
- **Connexion** par téléphone et mot de passe. Prévoir un **sélecteur de comptes de démo** : propriétaire avec 2 boutiques, propriétaire avec abonnement expiré, gérant.
- **Inscription du propriétaire** : téléphone → OTP WhatsApp simulé (code `123456`, avec renvoi et compte à rebours) → mot de passe → essai de 14 jours activé.
- **Première connexion d'un gérant** : OTP, puis définition du mot de passe.
- **Mot de passe oublié** : OTP, puis réinitialisation.

**Espace connecté**
- **Sélecteur de boutique** dans l'en-tête, pour le propriétaire qui en a plusieurs. Il propose l'option « Toutes » et la boutique choisie filtre toutes les listes. Le gérant est fixé sur sa boutique.
- **Accueil** :
  - total à récupérer ;
  - encaissé aujourd'hui et ce mois ;
  - compteurs par statut ;
  - liste « À relancer » ;
  - graphique des encaissements ;
  - bannière pendant l'essai ou si l'abonnement expire bientôt (`days_left`).
- **Clients** :
  - liste avec recherche (nom, téléphone), filtre « débiteurs », tri par montant dû, pagination infinie ;
  - fiche client : dettes, historique des versements, boutons d'appel et de WhatsApp ;
  - **« Encaisser un versement global »**, avec un aperçu en direct de la répartition sur chaque dette ;
  - création, modification et suppression.
- **Dettes** :
  - liste filtrable par statut, période et client ;
  - détail avec la liste des paiements ;
  - action « Payer » : montant ≤ restant, méthode, référence ;
  - création et modification ;
  - « Annuler la dette » : visible uniquement par le propriétaire.
- **Versements** : annulation d'un versement entier, avec confirmation. Réservée au propriétaire.
- **Rappels** :
  - préparation : choix du canal et du ton, puis aperçu du message généré (nom, montant, échéance, boutique) ;
  - **« Envoyer »** ouvre réellement WhatsApp (`https://wa.me/221…?text=`) ou l'app SMS (`sms:`) via `url_launcher`, puis enregistre le rappel au statut `envoye` ;
  - historique des rappels.
- **Gérants** (propriétaire uniquement) :
  - liste par boutique avec un quota affiché (« 2 / 3 », ou « illimité ») ;
  - ajout, modification, activation/désactivation, réinitialisation de l'accès, suppression, transfert ;
  - quand le quota est atteint : bouton désactivé, message explicatif et lien vers les offres.
- **Mon entreprise / Abonnement** (propriétaire uniquement) :
  - profil du tenant, avec le logo ;
  - forfait actuel, date de fin, jours restants ;
  - offres disponibles et contact pour payer.
- **Abonnement expiré / compte désactivé** : page bloquante, avec les offres et le contact pour payer. Le routeur y redirige automatiquement.
- **Profil** : modifier ses informations, changer son mot de passe, se déconnecter, réinitialiser les données de démo.
- **Page 404** pour les routes inconnues.

**Rôles** : les menus et les actions s'adaptent au rôle. Un gérant ne voit jamais les pages Gérants, Mon entreprise / Abonnement, ni les actions d'annulation. Le routeur le bloque aussi s'il saisit l'URL directement.

---

## 6. Données statiques

Données réalistes en Dart, cohérentes entre elles :
- 2 tenants ;
- 3 boutiques à Dakar et Thiès (quartiers réels : Médina, Parcelles Assainies, Grand Yoff, etc.) ;
- environ 30 clients aux noms sénégalais ;
- environ 80 dettes, avec tous les statuts représentés ;
- environ 120 paiements, dont des versements globaux ;
- les 4 forfaits ;
- quelques gérants, dont une boutique au quota plein ;
- quelques rappels.

Toutes les données sont modifiables **en mémoire**. Après chaque paiement ou annulation, recalcule `montant_paye`, `montant_restant` et `statut`.

En V1, les données sont réinitialisées à chaque lancement et à chaque rechargement de la page Web. **Dis-le clairement dans le README**, sans prétendre le contraire. Seule la session de démo (compte choisi, boutique sélectionnée) est conservée, avec `shared_preferences`, qui est compatible Web.

---

## 7. Design et responsive

- L'utilisateur principal est un commerçant sur un téléphone Android d'entrée de gamme :
  - interface sobre et lisible ;
  - grandes cibles tactiles ;
  - actions principales (« Nouvelle dette », « Encaisser ») accessibles en un geste.
- **Thème** : Material 3 et `ColorScheme` de marque. Les statuts de dette ont des couleurs dédiées :
  - `en_retard` = rouge ;
  - `a_aujourdhui` = orange ;
  - `en_attente` = bleu/gris ;
  - `payee` = vert ;
  - `annulee` = gris barré.
- **Mise en page selon la largeur** :
  - **< 600 px** : `NavigationBar` en bas et FAB ;
  - **600 à 1024 px** : `NavigationRail` ;
  - **> 1024 px** : sidebar, contenu élargi, listes en tableau et vue maître/détail.

  N'étire pas l'interface mobile sur grand écran.
- **Composants partagés** : `StatCard`, `MontantFcfa`, `StatutBadge`, `EmptyState`, `ErrorState` (avec « Réessayer »), skeleton de chargement, `ConfirmDialog`, `SearchField`, barre de filtres, `PageHeader`, champ de téléphone avec masque `+221`, champ de montant FCFA.
- **Partout** :
  - états de chargement, de liste vide et d'erreur ;
  - SnackBars de succès et d'erreur ;
  - confirmation avant toute action destructive ;
  - formulaires validés.
- **Accessibilité** : `Semantics` et labels, contrastes AA, navigation au clavier et souris sur desktop/Web, respect de la taille de texte du système.
- **Aucun bouton décoratif** : chaque bouton déclenche une action réelle.

---

## 8. PWA (Flutter Web)

- **Manifest et `index.html`** :
  - nom « Cahier Bi », nom court, description en français ;
  - `theme_color` et `background_color` alignés sur le thème ;
  - icônes 192 et 512 px, y compris en version *maskable*, et favicon ;
  - `lang="fr"`.
- **Service worker** : vérifie ce que fait **la version de Flutter installée**. Le service worker généré par Flutter a été déprécié, et son comportement varie selon les versions.
  - Ne l'écrase pas à l'aveugle.
  - Si un cache hors ligne est nécessaire, ajoute une solution simple et maintenable.
  - Vérifie que l'utilisateur récupère bien une nouvelle version après un déploiement (pas de cache bloqué).
- **URLs** : utilise `usePathUrlStrategy()`. Le rechargement direct d'une route (par exemple `/clients/12`) doit fonctionner. Documente la règle de réécriture côté serveur (Nginx ou équivalent), qui renvoie vers `index.html`.
- **Hors ligne** : sois honnête sur ce qui fonctionne.
  - L'application elle-même peut se charger depuis le cache.
  - Les modifications faites en mémoire ne sont pas conservées.
  - Plus tard, les données API demanderont une vraie stratégie hors ligne : prévois-la seulement dans le README, ne l'implémente pas.
- **README** : documente l'exigence de HTTPS en production et les limites selon la plateforme :
  - installation sur iOS via Safari (« Sur l'écran d'accueil »), sans invite d'installation ;
  - comportement de `wa.me` et de `sms:` selon le navigateur.

---

## 9. Vérifications obligatoires

Exécute réellement chaque étape et corrige jusqu'à ce qu'elle passe :

1. `flutter pub get`, puis `dart run build_runner build --delete-conflicting-outputs`.
2. `dart format .` et `flutter analyze` : zéro erreur, zéro avertissement.
3. **Tests unitaires** :
   - répartition d'un versement global (montant exact, partiel, supérieur au total dû) ;
   - calcul du statut d'une dette ;
   - quota de gérants (ajout et transfert) ;
   - annulation d'un versement ;
   - formatage des montants FCFA et des téléphones ;
   - mapping JSON ↔ entité.
4. **Tests de widgets** :
   - connexion avec un compte de démo ;
   - liste des clients avec recherche ;
   - paiement d'une dette ;
   - masquage des actions propriétaire pour un gérant ;
   - redirection vers la page d'abonnement expiré.
5. `flutter test` : tous les tests passent.
6. `flutter build web --release` et `flutter build apk --debug` (ou explique pourquoi c'est impossible dans l'environnement).
7. Vérifie la navigation directe vers 2 ou 3 routes sur le build Web servi en local.

Ne déclare jamais réussi un test ou un build que tu n'as pas exécuté.

---

## 10. Méthode et livrables

**Ordre de travail :**
1. Lis le backend (routes, contrôleurs, enums).
2. Crée le squelette : thème, routeur, DI, `core`, layout responsive.
3. Implémente l'authentification et les comptes de démo.
4. Écris les données statiques et les datasources fake.
5. Implémente les features, dans cet ordre : dashboard, clients, dettes/paiements, gérants, abonnement, rappels, profil.
6. Configure la PWA.
7. Ajoute les tests.
8. Lance les builds.

**Livrables :**
- le projet complet ;
- un **README** contenant :
  - l'installation, le lancement mobile et Web, les tests, le build, le déploiement Web ;
  - les identifiants des comptes de démo ;
  - la procédure pour brancher l'API Laravel (fichier et provider à changer) ;
  - les limites connues ;
- la liste des vérifications réellement exécutées, avec leur résultat.
