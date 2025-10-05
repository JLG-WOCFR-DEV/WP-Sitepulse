# Plan d'amélioration des fonctions

Ce document répertorie les fonctions de SitePulse qui gagneraient à être alignées sur les standards observés dans les solutions professionnelles de monitoring WordPress/SaaS. Les propositions tiennent compte des attentes en matière de résilience, d'observabilité et d'expérience utilisateur premium.

## `sitepulse_delete_transients_by_prefix()`

- **Constat :** la fonction nettoie désormais les entrées `_transient_` et `_transient_timeout_`, ce qui la rapproche des purgeurs distribués utilisés par des suites comme New Relic ou Kinsta MU Manager. Il manque toutefois une prise en charge des caches objets persistants pour éviter les résidus côté Redis/Memcached, ainsi qu'un découpage par lots pour limiter les requêtes longues sur des tables volumineuses.【F:sitepulse_FR/includes/functions.php†L18-L60】
- **Pistes pro :**
  - Interroger `wp_using_ext_object_cache()` et purger explicitement les groupes `transient`/`site-transient` dans l'object cache distant.
  - Segmenter la suppression (pagination SQL ou curseurs) et journaliser le nombre d'entrées supprimées pour suivre l'efficacité des rotations.

## `sitepulse_get_recent_log_lines()`

- **Constat :** la lecture arrière est efficace pour des logs moyens, mais elle ne renvoie pas de métadonnées (horodatage, taille lue) et ne gère pas la contention de fichiers verrouillés, contrairement aux consoles temps réel proposées par des plateformes comme Datadog ou CloudWatch.【F:sitepulse_FR/includes/functions.php†L136-L225】
- **Pistes pro :**
  - Ajouter un verrouillage partagé (`flock`) et une tolérance aux logs volumineux (>10 Mo) via `SplFileObject` ou un streaming incrémental.
  - Retourner un tableau associatif `{ lines, bytes_read, truncated }` pour savoir si le résultat est complet ou tronqué.

## `sitepulse_get_ai_models()`

- **Constat :** la liste est filtrable mais recalculée à chaque appel, sans cache ni validation approfondie des clés, ce qui diffère des catalogues dynamiques gérés par des outils comme Jasper ou Writesonic qui mémorisent les modèles validés pour chaque workspace.【F:sitepulse_FR/includes/functions.php†L92-L135】
- **Pistes pro :**
  - Mettre en place un cache transitoire (ou un cache runtime statique) pour éviter des filtrages coûteux sur des listes personnalisées.
  - Enrichir la validation (longueur des identifiants, disponibilité régionale, coût estimé) et exposer un schéma de compatibilité pour les interfaces React.

## `sitepulse_sanitize_alert_interval()`

- **Constat :** seules quatre valeurs fixes sont acceptées, ce qui limite la personnalisation par rapport aux systèmes d'alerte professionnels (Statuspage, Better Uptime) qui autorisent des fenêtres plus fines (1-120 minutes) et des stratégies progressives.【F:sitepulse_FR/includes/functions.php†L226-L250】
- **Pistes pro :**
  - Autoriser des paliers supplémentaires (p. ex. 1, 2, 5, 10, 15, 30, 60) et ajouter un mode « intelligent » qui ajuste l'intervalle selon la gravité des erreurs récentes.
  - Documenter une API permettant aux intégrations tierces de définir leurs propres contraintes via un filtre.

## `sitepulse_get_speed_thresholds()`

- **Constat :** la fonction force le seuil critique à être strictement supérieur au seuil d'avertissement, mais ne journalise pas ces corrections et n'offre pas de contextes multiples (mobile/desktop) comme le font des suites d'observabilité telles que Calibre ou SpeedCurve.【F:sitepulse_FR/includes/functions.php†L48-L91】
- **Pistes pro :**
  - Stocker les ajustements correctifs dans un journal pour faciliter l'audit lors des revues de performance.
  - Étendre la structure de retour pour inclure des profils (mobile, desktop, LCP, TTFB) et des seuils dépendant des objectifs Core Web Vitals.

