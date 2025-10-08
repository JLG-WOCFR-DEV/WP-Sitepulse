# Code Review Notes

## Statut des points critiques

- ✅ **REST permission filter bypass** – La fonction `sitepulse_uptime_rest_schedule_permission_check()` renvoie désormais l'objet `WP_Error` retourné par le filtre `sitepulse_uptime_rest_schedule_permission` et respecte explicitement les valeurs booléennes. Les intégrateurs peuvent à nouveau interrompre l'exécution en renvoyant un `WP_Error`, sans que celui-ci soit converti en `true`.【F:sitepulse_FR/modules/uptime_tracker.php†L184-L211】

- ✅ **Incident start regression on unsorted logs** – `sitepulse_normalize_uptime_log()` prépare maintenant un tableau trié par timestamp avant de propager `incident_start`. Les entrées sont ordonnées via `usort()` puis parcourues séquentiellement, ce qui garantit que la propagation s'appuie sur les échantillons antérieurs plutôt que suivants.【F:sitepulse_FR/modules/uptime_tracker.php†L904-L996】
- ✅ **Garde-fous sur la queue distante** – `sitepulse_uptime_normalize_remote_queue()` impose un TTL filtrable, supprime les doublons et tronque la file à une taille maximale configurable avant chaque mise à jour. `sitepulse_uptime_get_queue_next_scheduled_at()` se charge ensuite de planifier le prochain cron sur l'échéance la plus proche pour éviter les dérives lorsque WP-Cron est ralenti.【F:sitepulse_FR/modules/uptime_tracker.php†L706-L836】
- ✅ **Instrumentation de la queue distante** – Les métriques consolidées (compteurs de purge, backlog moyen/maxi, prochain déclenchement) sont stockées dans l’option `SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS` et rafraîchies à chaque normalisation, avec un hook dédié pour alimenter des dashboards externes.【F:sitepulse_FR/modules/uptime_tracker.php†L724-L888】【F:sitepulse_FR/tests/sitepulse_uptime_tracker_test.php†L115-L174】

## Points à surveiller

- Exposer ces métriques instrumentées dans l’UI (widget, page statut) et ajouter une alerte proactive lorsque `delayed_jobs` dépasse un seuil critique.
