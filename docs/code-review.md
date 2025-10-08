# Code Review Notes

## Statut des points critiques

- ✅ **REST permission filter bypass** – La fonction `sitepulse_uptime_rest_schedule_permission_check()` renvoie désormais l'objet `WP_Error` retourné par le filtre `sitepulse_uptime_rest_schedule_permission` et respecte explicitement les valeurs booléennes. Les intégrateurs peuvent à nouveau interrompre l'exécution en renvoyant un `WP_Error`, sans que celui-ci soit converti en `true`.【F:sitepulse_FR/modules/uptime_tracker.php†L184-L211】

- ✅ **Incident start regression on unsorted logs** – `sitepulse_normalize_uptime_log()` prépare maintenant un tableau trié par timestamp avant de propager `incident_start`. Les entrées sont ordonnées via `usort()` puis parcourues séquentiellement, ce qui garantit que la propagation s'appuie sur les échantillons antérieurs plutôt que suivants.【F:sitepulse_FR/modules/uptime_tracker.php†L904-L996】

## Points à surveiller

- Les nouvelles files d'attente d'agents (`SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE`) n'imposent aucune limite de taille ni stratégie de purge automatique. Une surcharge de requêtes distantes pourrait grossir l'option de façon significative si `sitepulse_uptime_process_remote_queue()` n'est pas déclenché (WP-Cron désactivé ou erreur fatale). Ajouter un garde-fou (taille max, purge par timestamp) éviterait les dépassements de mémoire et faciliterait la reprise après incident.【F:sitepulse_FR/modules/uptime_tracker.php†L696-L767】【F:sitepulse_FR/modules/uptime_tracker.php†L768-L820】
