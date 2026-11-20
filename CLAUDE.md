# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Jira Tools : application Symfony 7.4 / PHP 8.2+ de pilotage d'équipes Jira / Confluence / GitLab, installée sous EasyPHP (Windows) par ses utilisateurs. **Tout est en français** : interface, messages, commentaires, messages de commit, README.

## Commandes

```bash
composer install                                  # en root (conteneur) : COMPOSER_ALLOW_SUPERUSER=1, sinon vendor/autoload_runtime.php manque et bin/console échoue
php vendor/bin/phpunit                            # toute la suite (Jira/Confluence/GitLab simulés, SQLite var/test.db)
php vendor/bin/phpunit --filter testDashboard     # un test (nom de méthode ou de classe)
php vendor/bin/phpunit tests/Unit/Delivery        # un dossier
php bin/console lint:twig templates
composer setup                                    # crée la base + doctrine:schema:update --force
php -S 127.0.0.1:8000 -t public                   # serveur local
```

- PHPUnit échoue sur toute dépréciation, notice ou warning (`failOnDeprecation/Notice/Warning`).
- **Pas de migrations** : le schéma suit les entités via `doctrine:schema:update --force` (aussi lancé par l'écran de mise à jour). Ne pas créer de fichiers dans `src/Migrations`.
- Le serveur intégré de PHP ne voit pas les variables d'environnement exportées : pour un aperçu, mettre `DATABASE_URL` dans un `.env.dev.local` (ignoré par git). Pour un aperçu avec de fausses données Jira, booter en `APP_ENV=test` (le client HTTP simulé n'existe que dans cet environnement).

## Architecture

**Accès aux API** — `src/Atlassian/AtlassianHttp.php` est le socle HTTP unique, instancié trois fois dans `config/services.yaml` (`app.jira_http`, `app.confluence_http`, `app.gitlab_http` : GitLab le réutilise avec un jeton Bearer). Il gère l'authentification (Basic si utilisateur renseigné, sinon Bearer/PAT), le cache (`APP_CACHE_TTL`, contourné par `?refresh=1` sur n'importe quelle page) et les erreurs (`AtlassianException`, rendue par `AtlassianExceptionListener`). `getMany()` envoie les requêtes **en parallèle** : les services regroupent leurs appels (`JiraClient::searchMany`, `getSprintsMany`, `getVersionsMany`) plutôt que de boucler sur `search()`. Garder ce principe (1 à 3 allers-retours par page) en ajoutant des contrôles.

**Clients** — `src/Jira/JiraClient.php` (recherche JQL, sprints, versions, statuts, périmètre d'un board, `mapIssue()` → `Issue`), `src/Confluence`, `src/GitLab`. Toute valeur insérée dans du JQL passe par `JiraClient::quote()` / `quoteList()`. Les champs Jira personnalisés (story points, sprint, flagged, rank) viennent de `.env` (`JIRA_*_FIELD`).

**Domaines** (un dossier par écran, services + objets valeur) :
- `src/Service` : « Qui fait quoi » (`ActivityService`), sprints, backlog, suivi & alertes (`HealthService`). `ProjectScope` traduit un projet de l'appli (clé Jira + composants + JQL additionnel) en JQL : plusieurs projets peuvent partager une clé Jira. `StatusAgeEvaluator` + `WorkingTime` calculent l'ancienneté dans le statut (week-ends exclus) et le niveau ok/warning/critical ; `WorkStatuses` dit quels statuts comptent comme « travail » selon le rôle du membre.
- `src/Delivery` : dépendances vers les versions des projets web services ; lit le filtre du board (`DeliveryScopeResolver`) et le calendrier Confluence (`DeliveryTableParser`, `VersionExtractor`, `DateParser`) ; date Jira prioritaire, écart Jira ≠ Confluence signalé.
- `src/Release` : contenu de MEP = commits `production..develop` lus dans GitLab, tickets extraits (`TicketKeyExtractor`) puis contrôlés dans Jira.
- `src/LocalUpdate` : mise à jour de l'installation depuis un zip Git puis exécution de commandes (`CommandRunner`, `Process::fromShellCommandline`). Lancée en processus séparé (`BackgroundLauncher` → commande `app:local-update`).

**Objets valeur hors conteneur** — les classes non-services (`Issue`, `Sprint`, `IssueView`, `DeliveryRow`, `ReleaseTicket`…) sont listées dans `exclude` de `config/services.yaml`. Une nouvelle classe de ce type doit y être ajoutée, sinon l'autowiring échoue.

**Paramétrage** — entités Doctrine pour équipes, membres, projets, pages Confluence, règles de statut, mise à jour locale (`LocalApp`, une seule ligne). Les réglages d'écran (livraisons, contenu MEP, statuts par rôle) sont des tableaux JSON dans l'entité clé/valeur `Setting` (`SettingRepository::getValue/setValue`), encapsulés par une classe `*Settings` avec ses `DEFAULTS`. Les écrans CRUD héritent de `Controller/Admin/AbstractCrudController` ; les écrans de paramétrage hors CRUD valident un jeton CSRF nommé (`isCsrfTokenValid('deliveries', …)`).

**Sécurité** — pas d'authentification (protection attendue au niveau du serveur web). `EventListener/LocalOnlyListener` réserve `/mises-a-jour` au poste (IP **et** Host en boucle locale, contre le DNS rebinding) ; ne pas l'affaiblir : cet écran exécute des commandes. `SecurityHeadersListener` pose X-Frame-Options/CSP/nosniff/Referrer-Policy. Les redirections `?redirect=` n'acceptent qu'un chemin local. Pas de `|raw` sur des données Jira/Confluence/GitLab.

## Tests

- `tests/Unit` : services et analyseurs purs (fixtures HTML dans `tests/fixtures`, ex. le calendrier Confluence) ; `tests/Unit/IssueFactory.php` fabrique des `Issue`.
- `tests/Functional/AppTest.php` : parcours HTTP complets. `FakeJiraHttpClient` simule Jira, Confluence (`https://jira.test`) et GitLab (`https://gitlab.test`) par correspondance d'URL : **un nouvel appel d'API doit y être ajouté**, sinon il répond 404. Le schéma SQLite est recréé dans `setUp()`.
- `tests/LocalUpdate` : mise à jour sur dossiers temporaires (`TempDirTrait`), `MockHttpClient` pour le zip.

## Interface

- Twig + Bootstrap 5.3 (fichiers locaux dans `public/vendor`, aucun CDN) + Bootstrap Icons. Macros partagées dans `templates/_macros.html.twig` (lignes de ticket, badges d'ancienneté/statut, avatars). Rafraîchissement automatique d'un bloc via `data-autorefresh` (`public/js/app.js`).
- Charte **Cardif Digital Platform v2.2** : couleurs uniquement via les variables de `public/css/tokens.css` (vert `--green-700` pour actions/identité ; rouge/orange/bleu réservés aux statuts erreur/avertissement/info), aucune couleur en dur ; `public/css/app.css` aligne les classes Bootstrap sur ces tokens. Open Sans locale. Un seul `<h1>` par page (style `h3` conservé dans les templates, agrandi par la CSS), pas d'italique, icônes décoratives `aria-hidden`.
- Textes : vouvoiement, boutons « verbe + complément » sans ponctuation finale.

## Documentation

Le README (en français) décrit chaque écran, chaque variable `.env` et le paramétrage pas à pas : le mettre à jour avec toute nouvelle variable d'environnement, option de paramétrage ou règle de calcul.
