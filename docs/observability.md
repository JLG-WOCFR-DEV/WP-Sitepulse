# Observabilité avancée dans SitePulse

Cette fiche décrit comment exploiter les trois nouveaux piliers de monitoring intégrés à SitePulse :

- le **traçage applicatif** (profiler de requêtes WordPress) ;
- la **surveillance des appels sortants** ;
- le **Real User Monitoring (RUM)** centré sur les Core Web Vitals.

Chaque composant est livré par défaut avec des protections de rétention, une API REST et une intégration complète dans l’interface d’administration.

## Traçage applicatif (profiler)

### Activer un profilage ponctuel

1. Ouvrez l’onglet **Vitesse** (`SitePulse → Speed`) en tant qu’administrateur.
2. Dans le panneau « Temps serveur », cliquez sur **Profiler cette page**. Un nonce dédié (`SITEPULSE_NONCE_ACTION_REQUEST_TRACE`) déclenche la prochaine requête instrumentée.
3. Rechargez la page cible (front ou admin). SitePulse active temporairement `SAVEQUERIES`, chronomètre chaque hook WordPress et historise les requêtes SQL associées.
4. Revenez sur la page Speed Analyzer pour visualiser :
   - les hooks les plus coûteux (temps total, exécutions, moyenne) ;
   - le top des requêtes SQL (durée, tables impliquées, appelant).

> 💡 Les données sont stockées dans la table `wp_sitepulse_request_traces` (préfixe multisite respecté). La rétention par défaut est de 14 jours et peut être ajustée via l’option `SITEPULSE_OPTION_REQUEST_TRACE_RETENTION_DAYS`.

### Points de vigilance

- Chaque session de profilage est verrouillée par un transient (`SITEPULSE_TRANSIENT_REQUEST_TRACE_SESSION_PREFIX`) pour éviter les captures concurrentes.
- Les traces expirent automatiquement via le cron `sitepulse_request_trace_cleanup`.
- Utilisez le filtre `sitepulse_request_profiler_payload` pour enrichir ou filtrer les données exposées en AJAX.

## Surveillance des appels sortants

### Fonctionnement

- Le module **Resource Monitor** accroche `http_api_debug` pour mesurer chaque `wp_remote_request()`.
- Les événements sont normalisés (URL, code HTTP, durée, taille, erreurs) avant d’être insérés dans `wp_sitepulse_http_monitor_events`.
- Deux seuils configurables pilotent les alertes :
  - latence moyenne (`SITEPULSE_OPTION_HTTP_MONITOR_LATENCY_THRESHOLD_MS`, 1 200 ms par défaut) ;
  - taux d’erreurs (`SITEPULSE_OPTION_HTTP_MONITOR_ERROR_RATE_THRESHOLD`, 20 % par défaut).

### Utilisation dans l’admin

1. Rendez-vous dans **SitePulse → Ressources**.
2. Le nouvel encart « Services externes » présente :
   - le top des domaines selon la latence et le volume ;
   - les erreurs récentes normalisées (timeouts, SSL, DNS) ;
   - un extrait des derniers appels avec horodatage.
3. Les statistiques sont également exposées via l’API REST : `GET /wp-json/sitepulse/v1/resources/http`.
4. Le formulaire intégré permet d’ajuster les seuils (latence p95, taux d’erreurs) et la rétention (jours) sans toucher aux options PHP.

### Maintenance

- La table est purgée quotidiennement par le cron `sitepulse_http_monitor_cleanup`.
- Ajustez la rétention via `SITEPULSE_OPTION_HTTP_MONITOR_RETENTION_DAYS` (14 jours par défaut).
- Excluez des domaines internes ou des webhooks SitePulse en branchant le filtre `sitepulse_http_monitor_should_ignore_request`.

## RUM Web Vitals

### Injection côté front

- Activez la collecte depuis **SitePulse → Speed → Web Vitals réels (RUM)**.
- Optionnel : cochez « Ne charger le script qu’après consentement explicite » pour exiger le cookie `sitepulse_rum_consent=1`.
- Une fois activé, le script `modules/js/sitepulse-rum-web-vitals.js` est injecté avec une configuration signée (endpoint REST, jeton, taux d’échantillonnage, device hint).

### Pipeline de collecte

1. Le script mesure LCP, FID et CLS via l’API Web Vitals et formate les valeurs (arrondi, rating).
2. Les mesures sont envoyées par lot (max. 50 échantillons) à `POST /wp-json/sitepulse/v1/rum` avec le jeton `SITEPULSE_OPTION_RUM_INGEST_TOKEN`.
3. Les données sont stockées dans `wp_sitepulse_rum_events` avec un hash d’URL pour accélérer les regroupements.
4. Le cron `sitepulse_rum_cleanup` purge les mesures plus anciennes que la rétention configurée (30 jours par défaut).

### Visualisation et exports

- L’onglet Speed affiche :
  - un tableau de synthèse (p75, p95, moyenne) pour chaque métrique ;
  - les pages principales / device les plus affectés ;
  - un rappel du jeton et de l’endpoint pour vos intégrations externes.
- Les tableaux de bord personnalisés disposent d’un widget « RUM » capable de filtrer par plage (7/30/90 jours) et par device.
- Le tableau « Resources » du dashboard récapitule également les appels externes (volume, p95, taux d’erreurs et service le plus sollicité sur 24 h).
- Utilisez `GET /wp-json/sitepulse/v1/rum/aggregates` pour intégrer les médianes/p75/p95 dans vos outils BI.

### Personnalisation

- Modifiez le taux d’échantillonnage via `sitepulse_rum_settings` (valeur `sample_rate`, 0-1).
- Filtrez le payload front (`sitepulse_rum_frontend_payload`) pour ajouter des attributs maison (ID client, type de session anonymisé, etc.).
- Contrôlez les agrégations via `sitepulse_rum_calculated_aggregates` si vous souhaitez appliquer un IQR différent ou limiter le nombre de pages retournées.

## Nettoyage et désinstallation

Les trois modules purgent automatiquement leurs options, transients et tables dédiées lors de l’exécution de `uninstall.php`. Aucune donnée persistante ne subsiste après la suppression du plugin.

## Résolution de problèmes

| Symptôme | Piste |
| --- | --- |
| Le script RUM ne se charge pas | Vérifier que le module Speed Analyzer est activé et que `sitepulse_rum_settings[enabled]` vaut `true`. Contrôler la présence du cookie de consentement si l’option est active. |
| Aucune trace enregistrée | Confirmer que `SAVEQUERIES` peut être activé (pas de mu-plugin qui le désactive) et que la requête instrumentée n’est pas un appel REST interne ignoré par le profiler. |
| Aucun appel HTTP collecté | S’assurer que `http_api_debug` est déclenché (désactivé si `WP_HTTP_BLOCK_EXTERNAL` bloque les requêtes). Vérifier la liste blanche via `sitepulse_http_monitor_should_ignore_request`. |

Pour toute intégration avancée, consultez les tests unitaires (`tests/phpunit/test-request-profiler.php`, `tests/phpunit/test-http-monitor.php`, `tests/phpunit/test-rum.php`) qui illustrent la structure des données attendues.
