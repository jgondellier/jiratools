# Jira Tools — pilotage d'équipes Jira / Confluence

Application Symfony 7.4 (LTS) / PHP 8.2+ pour suivre vos équipes et vos projets Jira / Confluence au quotidien.

| Écran | Ce qu'il montre |
|---|---|
| **Qui fait quoi** (page d'accueil) | Pour chaque équipe et chaque membre : le(s) ticket(s) **sur lesquels il travaille à l'instant** (statuts paramétrables par rôle : en développement pour un dev, en test pour la QA…), depuis combien de temps il est dans son statut, et une **alerte rouge** pour qui n'a aucun ticket de travail (avec son dernier ticket touché). Ses autres tickets en cours sont affichés à part. Signale aussi les personnes au-delà de la limite WIP et les tickets en cours sans personne assignée. Actualisation automatique. |
| **Sprints** | Début / fin de chaque sprint actif, jours ouvrés restants, temps écoulé vs travail terminé (indicateur de santé), sprint suivant. Détail par sprint : tickets par statut, charge par personne, tickets non estimés, vélocité des derniers sprints. |
| **Backlog** | Backlog par projet, identifié par **composants** dans un projet Jira partagé. Filtres composant / étiquette / type / texte, synthèse (points, non estimés, sans étiquette). |
| **Dépendances livraison** | Versions non livrées du **projet web services** dont dépendent les tickets de mes projets (projet + composants) : pour chaque version, ses tickets WS liés, les tickets de mon projet qui en dépendent (« requis par ») et ce qui menace la date : livraison WS après la version de mes tickets (ou sans la marge), tickets WS pas commencés à l'approche de la date, bloqués, non assignés, qui traînent, non terminés après la date. **Date de la version : date de publication Jira, à défaut calendrier Confluence**, avec sa provenance ; tout **écart Jira ≠ Confluence est mis en évidence** (badge rouge, alerte, compteur). En dessous, les dépendances ticket par ticket et les tickets WS du board liés à aucun ticket de mes projets. |
| **Contenu MEP** | Ce qui part à la prochaine mise en production : tous les commits de la branche livrée (`develop`) absents de la production (`master` ou dernier tag), lus dans **GitLab** et rattachés à leur **merge request**. Les **tickets Jira cités** (titre, branche, description des MR, messages de commits) sont relus dans Jira et contrôlés : statut (pas commencé, encore en cours, ou hors des statuts attendus), **version corrective** absente, différente ou déjà livrée par rapport à la version de la MEP choisie, ticket introuvable. À l'inverse : tickets de la version **sans aucun code**, et merge requests / commits **sans ticket**. Bouton pour ouvrir dans Jira les tickets à corriger. |
| **Suivi & alertes** | Tickets qui traînent au-delà du seuil de leur statut, tickets bloqués (drapeau), sprint : non estimés / non assignés, tickets sans composant, vieux bugs, tickets réouverts, pages Confluence obsolètes. |
| **Projets** | Fiche projet : équipes, composants, board, pages Confluence rattachées (avec date de dernière modification), sprint en cours, tickets qui traînent. |
| **Mises à jour de l'application** | Met à jour cette installation depuis GitHub en un clic : téléchargement du zip d'une branche ou d'un tag, copie en conservant `.env.local`, `var/`, `vendor/`…, puis `composer install`, mise à jour de la base et `cache:clear`, avec le journal en direct. Accessible uniquement depuis le poste. |
| **Paramétrage** | Équipes, membres (import depuis Jira), projets (projet Jira + composants + board), pages Confluence, statuts de travail par rôle, seuils d'alerte par statut, test de connexion. |

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
2. **Projets** : clé du projet Jira, **composants** qui identifient le projet dans la backlog commune (plusieurs projets peuvent partager la même clé Jira), identifiant du **board Scrum** (nombre dans l'URL du board : `.../boards/42` ou `rapidView=42`), équipes, filtre JQL additionnel optionnel.
3. **Membres** : *Importer depuis Jira* liste les utilisateurs assignables d'un projet ; cochez-les et choisissez l'équipe. Pour un PO ou un manager, décochez « Alerter si aucun ticket de travail ».
4. **Pages Confluence** : collez l'URL de la page (l'identifiant est déduit), choisissez une catégorie et, si besoin, un délai d'alerte « non modifiée depuis N jours ».
5. **Statuts de travail par rôle** : tableau statuts Jira × rôles (Développeur, Testeur / QA, Lead, Product Owner, Autre), la liste des statuts étant lue dans vos projets Jira. Cochez, pour chaque rôle, les statuts qui signifient que le membre travaille sur le ticket : par exemple *En développement* pour les développeurs, *En test* pour la QA. Un membre sans ticket dans l'un des statuts de son rôle passe en rouge dans « Qui fait quoi » ; ses autres tickets en cours restent visibles à part.
   - Un rôle sans case cochée utilise la colonne **Par défaut** ; si elle est vide aussi, tous les statuts de la catégorie Jira *En cours* comptent.
   - Le rôle de chaque membre se choisit dans sa fiche (*Membres*).
6. **Dépendances livraison** :
   - **Périmètre lu dans le board** : le filtre du board de vos projets (ex. `project = APP OR (project = WS AND labels = mon-equipe)`) est lu automatiquement. Ses projets autres que les vôtres sont les projets web services, l'étiquette citée identifie vos tickets WS, et les tickets suivis sont exactement ceux du board. Le paramétrage affiche ce qui a été détecté ; les champs ci-dessous ne servent qu'à compléter (ou à remplacer si le board est illisible).
   - **Projets Jira des web services**, un par ligne, suivis du **nom du composant tel qu'écrit dans le calendrier** : `WS = WS`, `WSREF = WS-REF`. Indispensable si les versions Jira ne portent que le numéro (« 1.4.2 ») : c'est lui qui distingue « 1.4.2 WS » de « 1.4.2 WS-REF ». Inutile si les versions Jira s'appellent déjà « 1.4.2 WS ».
   - **Étiquette des tickets WS** : l'étiquette Jira que vous ajoutez sur vos tickets dans les projets WS (plusieurs possibles, séparées par des virgules). Elle définit les tickets WS suivis : chacun doit être lié à un ticket de vos projets (sinon il apparaît dans « Tickets WS non liés »), et seules leurs versions sont contrôlées, le projet WS pouvant en contenir d'autres. Un ticket WS lié à un de vos tickets mais sans l'étiquette est contrôlé et signalé.
   - **Page Confluence** du calendrier (URL ou identifiant) : l'application lit le premier tableau qui a une colonne « Date » et une colonne « Version » (libellés modifiables ; un en-tête identique est prioritaire : « Version » plutôt que « Leader de Version » ou « PV de version »). Une ligne = une date de livraison. La colonne Version peut contenir plusieurs versions, dans un sens ou dans l'autre : `1.4.2 WS 2.0.1 WS-REF`, une par ligne, `WS 1.4.2`, `1.28.0 Batch AB (commentaire)`, `4.7.63.0 BDD`… Quand une version est un **lien vers la version Jira**, son identifiant suffit au rapprochement ; sinon le nom est utilisé. Le numéro doit être identique (« 1.4 » ≠ « 1.4.2 ») et le composant aussi (« WS » ≠ « WS-REF », « 1.5.0 » ≠ « 1.5.0 WS »), sans tenir compte des majuscules ; dans la page, un nom de composant commence par une majuscule (« correctif 4.68.0 » n'est pas une version du composant « correctif »). Les cellules fusionnées et la macro date de Confluence sont gérées ; les dates sont lues sous les formes `12/03/2026`, `2026-03-12`, `12 mars 2026`…
   - L'écran de paramétrage **affiche ce qui a été lu** dans la page, cellule par cellule (« Versions reconnues » : numéro · composant) : vérifiez-le après chaque modification de la page ou des libellés. Un numéro seul n'est rapproché que d'une ligne sans composant.
   - **Marge** : nombre de jours minimum entre la livraison WS et la date de ma version.
   - **Prochaines livraisons (jours)** : horizon des versions affichées (45 par défaut), en plus des versions en retard ou sans date.
   - **Démarrage au plus tard (jours ouvrés)** : un ticket encore « à faire » à N jours ouvrés de la livraison passe en rouge (orange à 2 × N). 5 par défaut.
   - **Mes projets dans le calendrier** (facultatif) : `APP = PORTAIL` si les versions Jira de vos projets ne portent que le numéro alors que le calendrier les écrit « 1.5.0 PORTAIL ». Un numéro seul n'est jamais rapproché d'une ligne d'un autre composant (« 1.5.0 » ≠ « 1.5.0 WS ») : l'écran indique alors pour quel composant le numéro a été trouvé.
   - Sans étiquette paramétrée, les tickets WS contrôlés pour l'absence de lien sont ceux non terminés, assignés à un membre suivi ou présents dans un sprint actif de vos boards.
7. **Contenu des MEP (GitLab)** :
   - Dans `.env.local` : `GITLAB_BASE_URL=https://gitlab.mon-entreprise.fr` (sans `/api/v4`) et `GITLAB_API_TOKEN` : jeton personnel (ou de projet / groupe) avec la portée **`read_api`** (*GitLab → Préférences → Jetons d'accès*). Si GitLab est sur le réseau interne alors qu'un proxy est paramétré, ajoutez son nom dans `APP_HTTP_NO_PROXY`. Vérifiez avec *Tester la connexion*.
   - **Dépôts** : un par ligne, `groupe/projet` (l'URL du dépôt est acceptée). Pour un dépôt particulier : `groupe/projet | référence | branche`.
   - **Branche livrée** (`develop` par défaut) et **production** : branche ou tag déjà en production (`master` par défaut ; vide = dernier tag du dépôt). Le contenu de la MEP est l'équivalent de `git log production..develop`.
   - **Projets Jira à reconnaître** : clés de projets cherchées dans les commits (vide = vos projets et les projets web services). Les clés sont reconnues sans tenir compte des majuscules (`feature/app-123-export`).
   - **Statuts attendus** (facultatif) : par exemple `Prêt pour MEP`, `Terminé`. Vide : catégorie *Terminé* attendue, *En cours* en orange, *À faire* en rouge.
   - L'écran affiche la lecture de chaque dépôt (commits, merge requests, tickets cités) pour valider le paramétrage.
   - **Version de la MEP** : choisie dans l'écran parmi les versions non livrées de vos projets (par défaut, la ou les versions de la prochaine date de livraison). Une sous-tâche hérite de la version de son ticket parent.
8. **Seuils par statut** : par exemple `Code Review` → orange après 1 jour, rouge après 2 jours. Une règle peut être globale ou propre à un projet. Sans règle, les statuts de la catégorie « En cours » utilisent les seuils par défaut (`STATUS_DEFAULT_WARNING_DAYS` / `STATUS_DEFAULT_CRITICAL_DAYS`, 3 et 5 jours). Les week-ends sont exclus des durées (`APP_EXCLUDE_WEEKENDS`).

### Autres réglages (`.env.local`)

| Variable | Défaut | Rôle |
|---|---|---|
| `JIRA_DEPLOYMENT` | `auto` | `cloud` ou `server` ; `auto` détecte `*.atlassian.net` |
| `JIRA_STORY_POINTS_FIELD` | `customfield_10016` | Champ des story points (voir `https://<jira>/rest/api/2/field`) |
| `JIRA_SPRINT_FIELD` | `customfield_10020` | Champ Sprint |
| `JIRA_FLAGGED_FIELD` | `customfield_10021` | Champ « Flagged » (tickets bloqués) |
| `JIRA_RANK_FIELD` | `Rank` | Champ de tri « ordre du board » (backlog, sprint). Si votre Jira a plusieurs champs *Rank*, mettez son identifiant, ex. `cf[10019]`. Si Jira refuse ce tri, l'application trie automatiquement par priorité puis date de création (et le signale sur la backlog) |
| `JIRA_DONE_STATUSES` | `Done,Terminé,Closed,Fermé` | Statuts « terminés » de votre workflow (détection des réouvertures) |
| `APP_CACHE_TTL` | `60` | Cache des appels Jira/Confluence (s). Le bouton ⟳ ou `?refresh=1` force l'actualisation |
| `APP_REFRESH_SECONDS` | `120` | Actualisation automatique de « Qui fait quoi » (0 = désactivée) |

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

## Mises à jour de l'application (zip Git + commandes)

Menu **Paramétrage → Mises à jour de l'application** (accessible **uniquement depuis le poste**, à l'adresse `http://localhost/…` ou `http://127.0.0.1/…` : un autre nom, même pointant vers le poste, est refusé) ou commande `app:local-update` : met à jour **cette installation de Jira Tools**, et elle seule. Le dossier mis à jour est toujours celui où l'application est installée.

1. **Téléchargement** de l'archive zip du dépôt (par défaut `jgondellier/jiratools` sur GitHub, branche `main`) pour une branche, un tag ou un commit ;
2. **Copie dans le dossier de l'application** : les fichiers protégés (`.env.local`, `.env.*.local`, `var/`, `vendor/`, `.git/`…) ne sont jamais écrasés ; seuls les fichiers modifiés sont réécrits ; un fichier retiré du dépôt n'est supprimé que s'il avait été déposé par la mise à jour précédente (rien n'est supprimé à la première mise à jour) ; les fichiers remplacés ou supprimés sont sauvegardés dans `var/local-updates/backups/` (5 dernières mises à jour) ;
3. **Commandes**, dans l'ordre — par défaut :

```
{composer} install --no-interaction --optimize-autoloader
php bin/console doctrine:schema:update --force
php bin/console cache:clear
```

`php` en début de ligne est remplacé par `APP_UPDATE_PHP_BINARY` ; `{composer}` lance Composer : `APP_UPDATE_COMPOSER` s'il est renseigné, sinon `php composer.phar` si `composer.phar` se trouve à la racine de l'application (ou à côté de `php.exe`), sinon la commande `composer` du PATH. Une ligne commençant par `#` est ignorée. Par défaut, la mise à jour s'arrête à la première commande en erreur.

**Paramètres** (*Mises à jour → Paramètres*) : hébergement et dépôt (GitHub, GitLab — y compris auto-hébergé —, Bitbucket ou URL du zip avec `{ref}`), branche par défaut, jeton, fichiers protégés, commandes, variables d'environnement des commandes, options (suppression des fichiers retirés, sauvegarde, arrêt à la première erreur).

**Jeton (dépôt privé)** : laissez `env:GITHUB_TOKEN` dans le champ *Jeton* et ajoutez dans `.env.local` :

```dotenv
GITHUB_TOKEN=github_pat_xxx
```

Création sur GitHub : *Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token*, *Only select repositories* → `jiratools`, permission *Contents : Read-only*.

L'écran affiche les **vérifications** (URL du zip et authentification, dossier modifiable, PHP et Composer utilisés), permet une **simulation** (liste des fichiers qui changeraient, sans rien écrire ni lancer) et garde l'historique. La mise à jour tourne dans un **processus PHP séparé** et le **journal s'affiche en direct** ; l'écran peut être indisponible quelques secondes pendant `composer install` / `cache:clear`.

Le téléchargement utilise les réglages réseau `APP_HTTP_PROXY`, `APP_HTTP_CAFILE` (ajouté aux autorités publiques, PEM ou DER) et `APP_HTTP_VERIFY_PEER`.

**Erreur de tunnel / proxy** : le message indique le serveur refusé et le proxy utilisé (ligne *Proxy* des vérifications).
- *réponse 403* : le proxy bloque ce serveur. Avec GitHub, le zip est servi par **`codeload.github.com`** (après une redirection de `api.github.com`) : les deux doivent être autorisés par le proxy. À défaut, utilisez un dépôt interne (GitLab de l'entreprise) ou une « URL du zip ».
- *réponse 407* : le proxy demande une authentification : `APP_HTTP_PROXY=http://identifiant:mot-de-passe@proxy:8080` (caractères spéciaux encodés, `@` → `%40`).
- l'adresse du proxy commence en général par `http://`, même pour aller sur des sites en `https://`.

```bat
php bin/console app:local-update                        &rem mise à jour complète
php bin/console app:local-update --ref=develop --dry-run
php bin/console app:local-update --no-deploy            &rem commandes seules
```

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_UPDATE_PHP_BINARY` | *(vide)* | Exécutable PHP. **Sous EasyPHP / Apache, renseignez-le**, ex. `C:/EasyPHP/eds-binaries/php/php829vs16x64/php.exe` |
| `APP_UPDATE_COMPOSER` | *(vide)* | Commande Composer. Ex. `"{php} C:/composer/composer.phar"` (`{php}` = `APP_UPDATE_PHP_BINARY`) |
| `APP_UPDATE_BACKGROUND` | `1` | `0` : la mise à jour s'exécute dans la requête (la page attend la fin) |
| `APP_UPDATE_COMMAND_TIMEOUT` | `1800` | Durée maximale d'une commande (s, 0 = illimitée) |
| `APP_UPDATE_BACKUPS_KEPT` | `5` | Sauvegardes conservées |
| `APP_UPDATE_ALLOW_REMOTE` | `0` | `1` rend l'écran accessible depuis un autre poste (déconseillé : il lance des commandes sur ce poste) |

Si le journal reste « En attente » : vérifiez `APP_UPDATE_PHP_BINARY` et `var/local-updates/worker.log`, ou passez `APP_UPDATE_BACKGROUND=0`. Si Composer échoue depuis Apache faute de dossier utilisateur, ajoutez `COMPOSER_HOME=C:/Users/<vous>/AppData/Roaming/Composer` dans les variables d'environnement des paramètres.

> **Première installation de cette fonctionnalité** : récupérez une dernière fois le code à la main, puis lancez `composer install` et `composer setup` (nouvelles dépendances et nouvelles tables). Les mises à jour suivantes se font depuis l'écran.

## Charte graphique et sécurité

- L'interface suit le design system **Cardif Digital Platform v2.2** : police Open Sans (hébergée dans `public/vendor/open-sans`, licence SIL OFL), couleurs de `public/css/tokens.css` (vert `green-700` pour les actions et l'identité, rouge / orange / bleu réservés aux statuts erreur / avertissement / information), boutons en pilule, en-tête blanc avec lien d'évitement « Aller au contenu », pied de page vert. Bootstrap 5 reste le moteur (grille, utilitaires, menus) ; `public/css/app.css` l'aligne sur les tokens. Les icônes restent celles de Bootstrap Icons (la police Icomoon du thème officiel n'est pas distribuée).
- Toutes les pages sont servies avec des en-têtes de sécurité (affichage dans un cadre d'un autre site interdit, formulaires envoyés uniquement à l'application, pas de transmission de l'adresse des pages aux sites externes).
- **L'application n'a pas d'authentification** : en usage partagé, protégez-la au niveau du serveur web (authentification Apache, réseau restreint). Le paramétrage est modifiable par toute personne qui y accède.

## Fonctionnement

- « Ticket de travail » d'un membre = ticket dans un statut coché pour son rôle dans *Paramétrage → Statuts de travail par rôle* (à défaut, colonne *Par défaut*, puis tous les statuts de la **catégorie** Jira *In Progress*). La limite WIP porte sur ces tickets.
- Les seuils d'alerte par défaut et le contrôle « tickets qui traînent » portent sur toute la catégorie *In Progress* (un ticket bloqué en revue reste signalé).
- L'**entrée dans le statut** est calculée à partir de l'historique du ticket (dernier changement de statut).
- La **vélocité** compte les tickets terminés avant la clôture de chaque sprint.

### Appels à Jira et Confluence

- Les recherches indépendantes d'une page sont **envoyées en parallèle** (jusqu'à 10 connexions simultanées) : une page coûte le temps de 1 à 3 allers-retours vers Jira, quel que soit le nombre de contrôles.
- Les réponses sont gardées en cache `APP_CACHE_TTL` secondes (60 par défaut) ; les sprints futurs 10 min, les sprints clôturés 1 h, les statuts Jira 1 h, les pages Confluence 15 min. Le bouton ⟳ (ou `?refresh=1`) force la relecture.
- L'historique des tickets (volumineux) n'est demandé que là où il sert : tickets en cours et tickets non terminés des sprints.
- Seuls les champs utiles sont demandés ; la backlog n'est lue qu'une fois (les filtres sont appliqués par l'application, sauf la recherche plein texte) ; la vélocité des 6 derniers sprints tient en une seule recherche.

## Développement

```bash
php vendor/bin/phpunit          # tests unitaires et fonctionnels (Jira simulé, base SQLite)
php bin/console lint:twig templates
```

Structure : `src/Jira` et `src/Confluence` (clients REST), `src/Service` (règles de suivi : activité, sprints, backlog, alertes), `src/Controller` (écrans), `src/Controller/Admin` (paramétrage), `templates/`.
