# Bandeau KPI SitePulse

Ce document décrit la première itération du bandeau KPI attendu sur le tableau de bord SitePulse. L’objectif est d’offrir en un coup d’œil trois indicateurs métiers clés : le SLA global, les incidents actifs et la dette opérationnelle.

## 1. Structure visuelle

Le bandeau occupe la largeur du contenu principal et se compose de trois cartes alignées horizontalement (flex responsive) :

1. **SLA global (30 jours)**
   - Affichage principal : pourcentage SLA formaté sur 2 décimales.
   - Sous-titres : variation vs la période précédente (flèche + valeur), nombre total de contrôles.
   - Icône : `dashicons-shield` pour matérialiser la fiabilité.
2. **Incidents actifs**
   - Compteur d’incidents ouverts (statut `false` ou `unknown` sans résolution) avec badge couleur selon sévérité.
   - Liste déroulante (max 3) indiquant l’agent impacté, l’âge de l’incident et la cause racine (latence, code HTTP, contenu).
   - Icône : `dashicons-warning`.
3. **Dette opérationnelle**
   - Somme des tâches en file (jobs uptime + remédiations IA) pondérée par priorité.
   - Sparklines sur 7 jours pour visualiser la tendance.
   - Icône : `dashicons-portfolio`.

En mode mobile, les cartes passent en pile verticale avec marge interne accrue et la sparkline se replie en simple variation (ex : « +12 % sur 7 j »).

## 2. Sources de données

### SLA global
- **Tableau** : option `SITEPULSE_OPTION_UPTIME_LOG` (historique brut).
- **Agrégations** : fonctions `sitepulse_calculate_uptime_window_metrics()` et `sitepulse_calculate_agent_uptime_metrics()` déjà utilisées dans `modules/uptime_tracker.php` pour calculer les moyennes 7 j/30 j.【F:sitepulse_FR/modules/uptime_tracker.php†L3026-L3124】
- **Formatage** : `number_format_i18n()` + `sitepulse_uptime_format_relative_time()` pour les variations.

### Incidents actifs
- **Source primaire** : même option `SITEPULSE_OPTION_UPTIME_LOG` (filtrer les entrées `status === false` ou `status === 'unknown'`).
- **Métadonnées** : `incident_start` et `error` persistés via `sitepulse_run_uptime_check()` ; l’âge se calcule avec `current_time('timestamp') - incident_start`.
- **Fallback** : webhooks incidents (si activés) pour enrichir la cause racine.

### Dette opérationnelle
- **File remote uptime** : option `SITEPULSE_OPTION_UPTIME_REMOTE_QUEUE_METRICS` et analyse via `sitepulse_uptime_analyze_remote_queue()` ; la priorisation numérique ajoutée permet de pondérer la dette restante.【F:sitepulse_FR/modules/uptime_tracker.php†L1160-L1270】【F:sitepulse_FR/modules/uptime_tracker.php†L3096-L3350】
- **Remédiations IA** : option `SITEPULSE_OPTION_SPEED_AUTOMATION_QUEUE` (tâches IA en attente) et historique `SITEPULSE_OPTION_SPEED_AUTOMATION_HISTORY` pour la tendance.
- **Calcul** : dette = somme (jobs uptime restants × priorité) + tâches IA pondérées par sévérité (critique=3, warning=1). Sparkline alimentée par les archives journalières déjà persistées.

## 3. Prochaines étapes

1. Implémenter un composant React (ou template PHP) dédié pour factoriser l’accès aux options.
2. Ajouter un endpoint REST `sitepulse/v1/dashboard/kpi` exposant les trois indicateurs pour permettre un rafraîchissement sans recharger la page.
3. Brancher l’alerte visuelle sur les seuils existants (`sitepulse_plugin_impact_highlight_thresholds`) pour harmoniser les couleurs.
