Agis comme un architecte logiciel senior spécialisé en Angular et TypeScript.

Génère un vrai projet web Angular fonctionnel pour **Cahier Bi**, un SaaS de suivi des dettes clients pour les boutiques au Sénégal. Le backend Laravel existe déjà, mais ce lot ne doit pas l'appeler.

**Objectif :** créer uniquement le frontend web avec des données statiques en mémoire, sans appel HTTP. Les modèles et les services doivent toutefois suivre le contrat de l'API Laravel. Plus tard, on remplacera les services statiques par des services HttpClient sans toucher aux écrans.

---

**1. Contexte métier**

- **Tenant** = compte du propriétaire. Il détient l'abonnement et a un essai gratuit de 14 jours à l'inscription.
- **Boutique** : appartient à un tenant. Seul l'ADMIN crée les boutiques. Statut : `actif | inactif | suspendu`.
- **Rôles :**
    - `ADMIN` : super admin de la plateforme.
    - `PROPRIETAIRE` : possède une ou plusieurs boutiques.
    - `GERANT` : rattaché à une seule boutique via `boutique_id`.
- **Gérants** : chaque gérant appartient à une seule boutique. Le propriétaire peut le transférer vers une autre de ses boutiques. Le nombre maximum de gérants par boutique dépend du forfait (`max_gerants_par_boutique`) : gratuit = 1, boutique = 3, boutique_pro = 10, annuel = illimité (`null`).
    - Si une boutique dépasse sa limite, on bloque l'ajout et le transfert entrant. On ne supprime ni ne désactive rien.
- **Client** : appartient à une boutique.
- **Dette** : chaque nouvel achat crée une nouvelle dette. Champs : `montant`, `montant_paye`, `montant_restant`, `date_echeance`. Statut : `en_attente | a_aujourdhui | en_retard | payee | annulee`.
- **Paiement** : peut se faire sur une dette précise, ou en versement global du client.
    - Un versement global est réparti de la dette la plus ancienne à la plus récente. Les lignes `paiements` créées sont reliées par un même `versement` (uuid).
    - Méthodes : `especes | wave | orange_money | free_money | autre`.
- **Annulations** : seul le propriétaire peut annuler une dette ou un versement entier.
- **Rappels** (écran préparatoire) :
    - canal : `whatsapp | sms`
    - ton : `poli | normal | ferme`
    - statut : `en_attente | envoye | echoue`
- **Format :** devise FCFA (XOF), affichée sans décimales avec un séparateur de milliers (ex. `125 000 FCFA`). Téléphones au format sénégalais (+221 7X XXX XX XX). Toute l'interface est en français.

---

**2. Architecture**

- Utiliser la dernière version stable d'Angular : standalone components, signals, nouveau control flow (`@if`, `@for`), `inject()`, routes en lazy loading.
- Utiliser TypeScript en mode strict et les Reactive Forms typés.
- Appliquer une Clean Architecture organisée par fonctionnalité :
    - `src/app/core/` : auth simulée, guards par rôle, layout, intercepteurs prêts mais inactifs.
    - `src/app/shared/` : composants UI réutilisables, pipes (`fcfa`, `telephone`, `statutDette`), directives.
    - `src/app/features/<feature>/` avec trois dossiers :
        - `domain/` : modèles, interfaces de repository.
        - `data/` : jeux de données statiques et implémentation in-memory du repository.
        - `presentation/` : pages et composants.
- Fournir les repositories via des `InjectionToken`. Exemple : `CLIENT_REPOSITORY` → `InMemoryClientRepository`. Ainsi, on pourra brancher plus tard une implémentation HTTP en changeant une seule ligne.
- Les méthodes des repositories retournent des Observables, avec une latence simulée de 300 à 600 ms. Elles doivent aussi pouvoir simuler une erreur.
- Gérer l'état avec des signals dans des stores de feature. Ne pas utiliser NgRx.
- Choisir une seule librairie UI et s'y tenir : Angular Material ou Tailwind + composants maison.

---

**3. Modèles**

Les modèles reprennent les champs du backend, en snake_case :

- `Boutique { id, tenant_id, nom, slug, telephone, whatsapp, adresse, quartier, ville, logo_path, statut }`
- `Client { id, boutique_id, nom, telephone, whatsapp, photo_path, adresse, note }`. Ajouter les champs calculés `total_du` et `nb_dettes_ouvertes`.
- `Dette { id, boutique_id, client_id, reference, montant, montant_paye, montant_restant, date_creation, date_echeance, statut, note }`
- `Paiement { id, dette_id, boutique_id, client_id, enregistre_par, versement, montant, date_paiement, methode, reference, note }`
- `Rappel { id, dette_id, boutique_id, canal, ton, statut, envoye_le, message, erreur }`
- `SubscriptionPlan { id, name, duration_months, price, currency, max_gerants_par_boutique, description, is_active, position }`
- `Subscription { id, tenant_id, subscription_plan_id, plan, amount, currency, starts_at, ends_at, notes }`
- `User { id, name, telephone, role, tenant_id, boutique_id, is_active }`

Les enums TypeScript doivent correspondre exactement aux valeurs du backend.

---

**4. Écrans à générer**

**Public**

- Landing simple avec les offres d'abonnement (statiques, équivalent de `GET /subscription-offers`).
- Connexion par téléphone et mot de passe.
- Inscription du propriétaire. Parcours : téléphone → code OTP WhatsApp simulé (code `123456`) → définition du mot de passe.
- Mot de passe oublié.

**Espace Propriétaire / Gérant (`/app`)**

- **Dashboard** :
    - total dû, encaissé ce mois, nombre de dettes en retard, dettes échues aujourd'hui ;
    - top clients débiteurs ;
    - derniers paiements ;
    - un graphique simple.
- **Sélecteur de boutique** : pour le propriétaire qui a plusieurs boutiques. Le gérant est fixé sur sa boutique.
- **Clients** :
    - liste avec recherche (nom, téléphone), filtres (avec dette / à jour / en retard) et tri par montant dû ;
    - fiche client : dettes, historique des versements, bouton « Encaisser un versement global » avec un aperçu de la répartition sur les dettes, de la plus ancienne à la plus récente ;
    - formulaire de création et de modification.
- **Dettes** :
    - liste filtrable par statut, période et client ;
    - détail avec la liste des paiements et l'action « Payer » (montant ≤ montant restant, choix de la méthode) ;
    - formulaire de création et de modification ;
    - « Annuler la dette » : visible uniquement pour le propriétaire.
- **Versements** : annulation d'un versement entier, réservée au propriétaire.
- **Rappels** :
    - écran de préparation d'un rappel (choix canal + ton, aperçu du message) ;
    - historique simulé.
- **Gérants** (propriétaire uniquement) :
    - liste par boutique avec un indicateur de quota (ex. « 2 / 3 ») ;
    - ajouter, modifier, activer/désactiver, réinitialiser l'accès, supprimer ;
    - transférer vers une autre boutique du même propriétaire.
    - Respecter la règle de quota : bouton désactivé et message explicatif quand la limite est atteinte.
- **Mon entreprise / Abonnement** (propriétaire uniquement) :
    - profil du tenant ;
    - forfait actuel ;
    - date de fin ;
    - jours d'essai restants.
- **Profil** : modifier ses informations et changer son mot de passe.

**Espace Admin (`/admin`)**

- **Dashboard plateforme** : nombre de tenants, boutiques, abonnements actifs, revenus.
- **Tenants** : liste, détail, activer/désactiver, restaurer.
- **Boutiques** : liste, création (rattachée à un tenant), modification, suppression, restauration.
- **Forfaits** : CRUD et activation/désactivation.
- **Abonnements** : vue d'ensemble et ajout d'un abonnement à un tenant.
- **Utilisateurs** : liste, ajout, activer/désactiver, réinitialiser l'accès.

---

**5. Navigation et rôles**

- Fournir un sélecteur de comptes de démo sur l'écran de connexion :
    - admin ;
    - propriétaire avec 2 boutiques ;
    - gérant.
- Les guards par rôle redirigent vers le bon espace selon le rôle.
- Les menus s'adaptent au rôle : un gérant ne voit ni Gérants, ni Abonnement, ni les actions d'annulation.
- Si l'abonnement est expiré, afficher une page « Abonnement expiré » (équivalent du middleware `subscription`).
- Layout responsive :
    - sidebar sur desktop ;
    - drawer et bottom nav sur mobile.
- Toutes les pages doivent être accessibles. Tous les boutons, menus et actions doivent fonctionner.
- Gérer partout :
    - chargement (skeletons) ;
    - liste vide ;
    - erreur simulée avec bouton « Réessayer » ;
    - confirmations avant les actions destructrices ;
    - toasts de succès et d'erreur.

---

**6. Données statiques**

- Créer des jeux de données réalistes en TypeScript :
    - 2 tenants ;
    - 3 boutiques (Dakar, Thiès, quartiers réels) ;
    - environ 30 clients aux noms sénégalais ;
    - environ 80 dettes avec tous les statuts représentés ;
    - environ 120 paiements, dont des versements globaux ;
    - les 4 forfaits ;
    - quelques rappels.
- Toutes les données sont modifiables en mémoire pendant l'exécution : ajout, modification, paiement, annulation.
- Recalculer automatiquement `montant_paye`, `montant_restant` et `statut` d'une dette après chaque paiement ou annulation.
- Calculer le statut selon `date_echeance` par rapport à aujourd'hui.
- N'utiliser ni HttpClient ni backend réel.

---

**7. Design**

- Interface professionnelle, moderne, sobre et lisible. Elle doit pouvoir servir sur le téléphone d'un commerçant.
- Palette cohérente définie en variables CSS. Les statuts de dette ont des couleurs dédiées :
    - en_retard = rouge ;
    - a_aujourdhui = orange ;
    - en_attente = bleu/gris ;
    - payee = vert ;
    - annulee = barré/gris.
- Fournir des composants réutilisables : stat-card, data-table responsive (cartes sur mobile), badge de statut, montant FCFA, empty-state, confirm-dialog, page-header, champ de recherche, filtres.
- Respecter l'accessibilité : labels, contrastes, navigation au clavier.

---

**8. Résultat attendu**

- Créer réellement le projet avec Angular CLI (`ng new`), pas seulement des extraits de code.
- Vérifier que les dépendances sont bien déclarées dans `package.json`.
- Exécuter `npm install`, `ng build` et `ng lint` (ajouter ESLint si besoin). Corriger toutes les erreurs.
- Fournir les commandes pour lancer l'application (`ng serve`) et les identifiants des comptes de démo.

Commence par le squelette (structure, core, layout, routes, guards). Ensuite :

1. les modèles et les données statiques ;
2. les écrans propriétaire/gérant ;
3. les écrans admin.

Termine par une vérification complète du build.
