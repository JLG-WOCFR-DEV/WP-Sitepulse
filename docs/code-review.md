# Code Review Notes

## High Priority

- **REST permission filter bypass** – `sitepulse_uptime_rest_schedule_permission_check()` gère désormais explicitement les réponses `WP_Error`/`WP_REST_Response` et ne caste plus la valeur du filtre en booléen, renvoyant une erreur standard lorsqu’aucune autorisation stricte n’est accordée.【F:sitepulse_FR/modules/uptime_tracker.php†L196-L258】

- **Incident start regression on unsorted logs** – La normalisation des entrées `incident_start` est effectuée sur un tableau trié chronologiquement avant calcul, empêchant les régressions mentionnées lorsque les données sources sont ordonnées du plus récent au plus ancien.【F:sitepulse_FR/modules/uptime_tracker.php†L1336-L1398】

## Suggestions

- Continuer à étendre les stratégies d’authentification personnalisées autour de l’API REST (clé applicative, OAuth) si nécessaire.
- Surveiller la qualité des journaux importés en masse pour détecter d’éventuelles anomalies de timestamp en amont.
