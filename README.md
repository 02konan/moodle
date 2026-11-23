# Inscriptions étudiants Moodle

Portail de pré-inscription des étudiants : formulaire en cascade (section → cycle → niveau → classe),
backoffice d'administration et export CSV pour l'import d'utilisateurs Moodle (cohorte, cours et groupe `GP/<cohorte>`).

## Déploiement sur Dokploy

1. **Créer un projet** puis un service **Compose** pointant sur ce dépôt (fichier `docker-compose.yml`).
2. **Environment** : renseigner les variables (modèle dans `.env.example`), au minimum :
   `DB_PASS`, `DB_ROOT_PASS`, `REFERENTIEL_API_URL`, et pour le premier démarrage `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
3. **Domains** : ajouter le domaine sur le service `app`, port `80`, HTTPS activé (Let's Encrypt).
4. **Deploy**.

Au démarrage, le conteneur `app` :
- attend la base, applique les scripts `sql/*.sql` (idempotents) ;
- crée ou met à jour le compte admin si `ADMIN_EMAIL` et `ADMIN_PASSWORD` sont définis
  (le mot de passe est réécrit à chaque démarrage : videz ces variables une fois le compte créé si vous le changez ailleurs).

Données persistantes : volumes `db_data` (MariaDB) et `storage` (cache du référentiel).
Sauvegardes : configurer une sauvegarde du volume `db_data` ou un `mariadb-dump` planifié dans Dokploy.

Créer un autre administrateur (Dokploy → service → Terminal, conteneur `app`) :

```bash
php bin/create-admin.php admin@exemple.com "MotDePasseSolide"
```

## Service web Moodle (page Doublons)

La page **Doublons** peut vérifier dans Moodle quels comptes en double ont déjà servi, garder ceux-là
et supprimer les accès jamais utilisés (dans Moodle et dans la base). À configurer une fois dans Moodle :

1. **Administration du site → Développement → Fonctionnalités avancées** : activer les *services web*.
2. **Administration du site → Serveur → Services web → Gérer les protocoles** : activer *REST*.
3. **… → Services web → Services externes** : *Ajouter* un service (ex. « Inscriptions »), coché *Activé* et
   *Utilisateurs autorisés seulement*, puis *Fonctions* → ajouter `core_user_get_users_by_field` et `core_user_delete_users`.
4. Dans ce service, **Utilisateurs autorisés** : ajouter un compte administrateur.
5. **… → Services web → Gérer les jetons** : *Créer un jeton* pour ce compte et ce service.
6. Dans Dokploy (onglet *Environment*) : `MOODLE_URL=https://votre-moodle.exemple` et `MOODLE_TOKEN=<le jeton>`, puis *Deploy*.

Le jeton donne le droit de supprimer des comptes : ne le partagez pas et ne le mettez pas dans le dépôt.

## Lancer en local

```bash
cp .env.example .env
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

Puis ouvrir http://localhost:8080 (formulaire) et http://localhost:8080/admin/ (backoffice).

## Structure

- `public/` : racine web (formulaire, `api/`, `admin/`, `assets/`)
- `src/` : code partagé (configuration, référentiel, filtres, cours Moodle)
- `includes/` : gabarit commun du backoffice (`header.php` : sidebar et ouverture de `<main>`, `footer.php` : fermeture et scripts)
- `sql/` : schéma et migrations
- `bin/` : scripts en ligne de commande (`migrate.php`, `create-admin.php`)
- `docker/` : configuration Apache/PHP et script de démarrage
