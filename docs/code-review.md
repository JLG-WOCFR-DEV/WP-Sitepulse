# Code Review Notes

## Statut des points critiques

- ✅ **REST permission filter bypass** – La fonction `sitepulse_uptime_rest_schedule_permission_check()` renvoie désormais l'objet `WP_Error` retourné par le filtre `sitepulse_uptime_rest_schedule_permission` et respecte explicitement les valeurs booléennes. Les intégrateurs peuvent à nouveau interrompre l'exécution en renvoyant un `WP_Error`, sans que celui-ci soit converti en `true`.【F:sitepulse_FR/modules/uptime_tracker.php†L184-L211】

- ✅ **Incident start regression on unsorted logs** – `sitepulse_normalize_uptime_log()` prépare maintenant un tableau trié par timestamp avant de propager `incident_start`. Les entrées sont ordonnées via `usort()` puis parcourues séquentiellement, ce qui garantit que la propagation s'appuie sur les échantillons antérieurs plutôt que suivants.【F:sitepulse_FR/modules/uptime_tracker.php†L904-L996】
- ✅ **Garde-fous sur la queue distante** – `sitepulse_uptime_normalize_remote_queue()` impose un TTL filtrable, supprime les doublons et tronque la file à une taille maximale configurable avant chaque mise à jour. `sitepulse_uptime_get_queue_next_scheduled_at()` se charge ensuite de planifier le prochain cron sur l'échéance la plus proche pour éviter les dérives lorsque WP-Cron est ralenti.【F:sitepulse_FR/modules/uptime_tracker.php†L706-L836】

## Points à surveiller

- Instrumenter la queue distante (compteur, temps d'attente moyen, alertes si la limite est atteinte) afin de détecter plus tôt les blocages WP-Cron et d'exposer des métriques proactives dans le dashboard ou via l'API.【F:sitepulse_FR/modules/uptime_tracker.php†L706-L836】
