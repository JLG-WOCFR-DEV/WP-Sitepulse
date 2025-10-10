# Revue de code – Module « AI Insights » de SitePulse

## Points critiques

### 1. Perte des résultats lors du mode de secours synchrone
Lorsque `wp_schedule_single_event()` échoue, le module exécute immédiatement l’analyse via `sitepulse_run_ai_insight_job()`. Si l’exécution réussit, la tâche est marquée « completed » puis supprimée (`sitepulse_ai_delete_job_data($job_id)`) avant de retourner l’identifiant au client.【F:sitepulse_FR/modules/ai_insights.php†L1788-L1816】

Or, le JavaScript de l’interface interroge ensuite `sitepulse_get_ai_insight_status()` avec cet identifiant. Comme les métadonnées viennent d’être supprimées, le serveur répond 404 « Tâche introuvable », l’interface affiche une erreur et l’utilisateur ne reçoit jamais le résultat malgré l’exécution effective. Il faudrait conserver l’entrée (au moins jusqu’au premier `status` réussi) ou renvoyer directement la payload fraîche dans la réponse JSON.

### 2. Expérience dégradée si le fallback AJAX échoue
En cas d’échec du `spawn_cron()`, le code tente une requête `admin-ajax.php` synchrone. Si cette requête échoue elle aussi (par exemple loopback HTTP désactivé), on logge l’erreur mais on renvoie quand même un `jobId` avec un statut « queued » qui ne sera jamais traité tant que le cron reste hors service.【F:sitepulse_FR/modules/ai_insights.php†L1858-L1890】 L’UI continuera de poller indéfiniment. Remonter une erreur côté client (ou repasser en exécution synchrone) éviterait de bloquer l’utilisateur sans feedback exploitable.

## Points positifs
- Gestion robuste des secrets AJAX (usage de `hash_equals`, stockage non autoloadé) et des nonces pour toutes les actions authentifiées.【F:sitepulse_FR/modules/ai_insights.php†L215-L276】【F:sitepulse_FR/modules/ai_insights.php†L2522-L2580】
- Hygiène soignée sur l’historique : normalisation, `wp_kses`, labels filtrés, export CSV prêt à l’emploi.【F:sitepulse_FR/modules/ai_insights.php†L628-L852】【F:sitepulse_FR/modules/ai_insights.php†L2255-L2330】

## Recommandations complémentaires
- Conserver la tâche en base tant que l’UI n’a pas accusé réception (ou retourner directement `result` côté PHP) pour les exécutions synchrones.
- Propager une erreur utilisateur quand aucune méthode d’exécution asynchrone n’a pu être déclenchée, afin d’éviter des statuts « queued » fantômes.
- Envisager une invalidation du transient `SITEPULSE_TRANSIENT_AI_INSIGHT` lors d’un `force_refresh`, afin qu’un nouvel essai sans forcer ne retombe pas sur une analyse obsolète si la génération échoue.
