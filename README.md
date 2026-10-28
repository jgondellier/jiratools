# Jira Tools — pilotage d'équipes Jira / Confluence

Application Symfony 7.4 (LTS) / PHP 8.2+ pour suivre vos équipes et vos projets Jira / Confluence au quotidien.

| Écran | Ce qu'il montre |
|---|---|
| **Qui fait quoi** (page d'accueil) | Pour chaque équipe et chaque membre : le(s) ticket(s) en cours **à l'instant**, depuis combien de temps il est dans son statut, et une **alerte rouge** pour qui n'a aucun ticket en cours (avec son dernier ticket touché). Signale aussi les personnes au-delà de la limite WIP et les tickets en cours sans personne assignée. Actualisation automatique. |
| **Sprints** | Début / fin de chaque sprint actif, jours ouvrés restants, temps écoulé vs travail terminé (indicateur de santé), sprint suivant. Détail par sprint : tickets par statut, charge par personne, tickets non estimés, vélocité des derniers sprints. |
| **Backlog** | Backlog par projet, identifié par **composants** dans un projet Jira partagé. Filtres composant / étiquette / type / texte, synthèse (points, non estimés, sans étiquette). |
| **Release note** | Tickets d'une version (fixVersion) ou d'un sprint **regroupés par étiquette** (un sujet = une étiquette). Copie mise en forme, export Markdown et **publication directe dans Confluence** (création ou mise à jour de la page). |
| **Suivi & alertes** | Tickets qui traînent au-delà du seuil de leur statut, tickets bloqués (drapeau), terminés sans étiquette, sprint : non estimés / non assignés, tickets sans composant, vieux bugs, tickets réouverts, pages Confluence obsolètes. |
| **Projets** | Fiche projet : équipes, composants, board, pages Confluence rattachées (avec date de dernière modification), sprint en cours, tickets qui traînent. |
| **Paramétrage** | Équipes, membres (import depuis Jira), projets (projet Jira + composants + board + espace Confluence), pages Confluence, seuils d'alerte par statut, test de connexion. |

## Installation sous EasyPHP (Windows)

Prérequis : EasyPHP Devserver avec **PHP 8.2 ou plus** et MySQL/MariaDB, [Composer](https://getcomposer.org/download/).
Extensions PHP à activer dans le `php.ini` si ce n'est pas déjà le cas : `pdo_mysql` (ou `pdo_sqlite`), `curl`, `mbstring`, `openssl`, `ctype`, `iconv`.

1. Récupérez le projet dans un dossier, par exemple `C:\EasyPHP\eds-www\jiratools`, puis installez les dépendances :
   ```bat
   cd C:\EasyPHP\eds-www\jiratools
   composer install
   ```
2. Créez un fichier **`.env.local`** à la racine (il n'est pas versionné) avec vos paramètres :
   ```ini
   APP_SECRET=une-chaine-aleatoire
   DATABASE_URL="mysql://root:@127.0.0.1:3306/jiratools?serverVersion=8.0.32&charset=utf8mb4"

   # Jira Cloud : e-mail + API token (https://id.atlassian.com/manage-profile/security/api-tokens)
   JIRA_BASE_URL=https://mon-site.atlassian.net
   JIRA_USER=prenom.nom@entreprise.fr
   JIRA_API_TOKEN=xxxxxxxx

   CONFLUENCE_BASE_URL=https://mon-site.atlassian.net/wiki
   CONFLUENCE_USER=prenom.nom@entreprise.fr
   CONFLUENCE_API_TOKEN=xxxxxxxx
   ```
   - **Jira / Confluence Server ou Data Center** : laissez `JIRA_USER` / `CONFLUENCE_USER` vides et mettez un *Personal Access Token* dans `*_API_TOKEN`.
   - **MariaDB** : `serverVersion=10.11.2-MariaDB`. **Sans MySQL** : `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"`.
3. Créez la base et les tables :
   ```bat
   composer setup
   ```
   (équivalent à `php bin/console doctrine:database:create --if-not-exists` puis `php bin/console doctrine:schema:update --force` — à relancer après une mise à jour de l'application.)
4. Rendez le dossier **`public/`** accessible :
   - **avec EasyPHP** : dans le tableau de bord, *Add directory* → chemin `C:\EasyPHP\eds-www\jiratools\public`, alias `jiratools`. L'adresse de l'application (du type `http://127.0.0.1/edsa-jiratools/`) est affichée dans le tableau de bord (le module Apache `mod_rewrite` doit être activé, le fichier `public/.htaccess` est fourni) ;
   - **ou avec le serveur intégré de PHP** : `php -S 127.0.0.1:8000 -t public` puis ouvrez <http://127.0.0.1:8000>.
5. Ouvrez *Paramétrage → Tester la connexion*, puis suivez l'assistant de la page d'accueil.

> En production (usage partagé), passez `APP_ENV=prod` dans `.env.local` et exécutez `php bin/console cache:clear`.

## Paramétrage

1. **Équipes** : nom, couleur, limite de tickets en cours par personne (WIP).
2. **Projets** : clé du projet Jira, **composants** qui identifient le projet dans la backlog commune (plusieurs projets peuvent partager la même clé Jira), identifiant du **board Scrum** (nombre dans l'URL du board : `.../boards/42` ou `rapidView=42`), équipes, espace Confluence et page parente des release notes, filtre JQL additionnel optionnel.
3. **Membres** : *Importer depuis Jira* liste les utilisateurs assignables d'un projet ; cochez-les et choisissez l'équipe. Pour un PO ou un manager, décochez « Alerter si aucun ticket en cours ».
4. **Pages Confluence** : collez l'URL de la page (l'identifiant est déduit), choisissez une catégorie et, si besoin, un délai d'alerte « non modifiée depuis N jours ».
5. **Seuils par statut** : par exemple `Code Review` → orange après 1 jour, rouge après 2 jours. Une règle peut être globale ou propre à un projet. Sans règle, les statuts de la catégorie « En cours » utilisent les seuils par défaut (`STATUS_DEFAULT_WARNING_DAYS` / `STATUS_DEFAULT_CRITICAL_DAYS`, 3 et 5 jours). Les week-ends sont exclus des durées (`APP_EXCLUDE_WEEKENDS`).

### Autres réglages (`.env.local`)

| Variable | Défaut | Rôle |
|---|---|---|
| `JIRA_DEPLOYMENT` | `auto` | `cloud` ou `server` ; `auto` détecte `*.atlassian.net` |
| `JIRA_STORY_POINTS_FIELD` | `customfield_10016` | Champ des story points (voir `https://<jira>/rest/api/2/field`) |
| `JIRA_SPRINT_FIELD` | `customfield_10020` | Champ Sprint |
| `JIRA_FLAGGED_FIELD` | `customfield_10021` | Champ « Flagged » (tickets bloqués) |
| `JIRA_DONE_STATUSES` | `Done,Terminé,Closed,Fermé` | Statuts « terminés » de votre workflow (détection des réouvertures) |
| `APP_CACHE_TTL` | `60` | Cache des appels Jira/Confluence (s). Le bouton ⟳ ou `?refresh=1` force l'actualisation |
| `APP_REFRESH_SECONDS` | `120` | Actualisation automatique de « Qui fait quoi » (0 = désactivée) |
| `RELEASE_NOTE_IGNORED_LABELS` | *(vide)* | Étiquettes à ignorer dans la release note (ex. `technique,interne`) |

### Options de connexion HTTP (réseau d'entreprise)

Elles s'appliquent à tous les appels Jira et Confluence. Leurs valeurs effectives sont visibles sur *Paramétrage → Tester la connexion*.

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_HTTP_VERIFY_PEER` | `1` | Vérifie le certificat SSL du serveur. `0` le désactive (déconseillé : préférez `APP_HTTP_CAFILE`) |
| `APP_HTTP_VERIFY_HOST` | `1` | Vérifie que le nom du serveur correspond au certificat |
| `APP_HTTP_CAFILE` | *(vide)* | Fichier PEM de l'autorité de certification interne, ex. `C:/EasyPHP/certs/ca-entreprise.pem` |
| `APP_HTTP_PROXY` | *(vide)* | Proxy sortant, ex. `http://proxy.entreprise.fr:8080` ou `http://user:mdp@proxy:8080` |
| `APP_HTTP_NO_PROXY` | *(vide)* | Hôtes joints sans proxy, séparés par des virgules, ex. `localhost,.intranet.fr`. Vide = variable système `NO_PROXY` |
| `APP_HTTP_TIMEOUT` | `30` | Délai maximal sans réponse, en secondes |

**Erreur « SSL certificate problem: unable to get local issuer certificate » sous Windows** : PHP ne connaît pas l'autorité qui a signé le certificat (souvent un proxy d'entreprise qui inspecte le SSL). Exportez le certificat racine de l'entreprise (depuis le navigateur, au format *Base64 / PEM*) et indiquez son chemin dans `APP_HTTP_CAFILE`, plutôt que de mettre `APP_HTTP_VERIFY_PEER=0`.

## Fonctionnement

- « En cours » = statuts de la **catégorie** Jira *In Progress* (bleus) : *En cours*, *Code Review*, *En test*… sont tous pris en compte.
- L'**entrée dans le statut** est calculée à partir de l'historique du ticket (dernier changement de statut).
- La **release note** regroupe les tickets par étiquette ; un ticket avec deux étiquettes apparaît dans les deux sujets, un ticket sans étiquette va dans « Autres évolutions ». Le contrôle « Terminés sans étiquette » de *Suivi & alertes* permet de les corriger avant de publier.
- La **vélocité** compte les tickets terminés avant la clôture de chaque sprint.

## Développement

```bash
php vendor/bin/phpunit          # tests unitaires et fonctionnels (Jira simulé, base SQLite)
php bin/console lint:twig templates
```

Structure : `src/Jira` et `src/Confluence` (clients REST), `src/Service` (règles de suivi : activité, sprints, backlog, release note, alertes), `src/Controller` (écrans), `src/Controller/Admin` (paramétrage), `templates/`.
