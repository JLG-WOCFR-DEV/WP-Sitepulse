# Revue du module « Analyses par IA »

## Portée
Cette revue couvre le flux AJAX `sitepulse_generate_ai_insight`, la page d’administration dédiée et les utilitaires de préparation des variantes texte/HTML introduits dans `tests/phpunit/test-ai-insights.php`.

## Tests automatisés
- Le scénario `test_successful_request_sanitizes_and_caches_payload` vérifie la sanitation du texte, la présence du timestamp et le stockage du transient avant de valider que la réponse JSON est bien signalée comme non issue du cache.【F:tests/phpunit/test-ai-insights.php†L75-L154】
- `test_prepare_insight_variants_filters_disallowed_markup` confirme que les balises interdites sont écartées tout en préservant la structure HTML autorisée, en s’appuyant sur `sitepulse_ai_prepare_insight_variants()` et `sitepulse_ai_sanitize_insight_text()`.【F:tests/phpunit/test-ai-insights.php†L287-L310】【F:sitepulse_FR/modules/ai_insights.php†L68-L126】
- Le test `test_job_secret_is_persistent_and_filterable` valide que le secret partagé est pérenne, respecte la longueur attendue et peut être filtré sans rompre la compatibilité descendante.【F:tests/phpunit/test-ai-insights.php†L322-L349】【F:sitepulse_FR/modules/ai_insights.php†L146-L198】

## Accessibilité
- La page d’administration expose un `role="status"` associé à `aria-live="polite"` pour annoncer les mises à jour en respectant les attentes des lecteurs d’écran.【F:tests/phpunit/test-ai-insights.php†L197-L221】【F:sitepulse_FR/modules/ai_insights.php†L2398-L2413】
- Les erreurs sont restituées via un conteneur `role="alert"` focusable (`tabindex="-1"`), ce qui garantit un retour vocal immédiat tout en permettant le focus manuel pour relire le message.【F:tests/phpunit/test-ai-insights.php†L221-L241】【F:sitepulse_FR/modules/ai_insights.php†L2397-L2408】
- Les actions principales sont regroupées dans un bloc marqué `aria-busy`, utilisé par le JavaScript pour signaler les traitements en cours et éviter la confusion lors des rafraîchissements rapides.【F:tests/phpunit/test-ai-insights.php†L242-L253】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L32-L52】【F:sitepulse_FR/modules/js/sitepulse-ai-insights.js†L419-L437】

## Vérifications visuelles
- Les classes `sitepulse-ai-insight-status` et `sitepulse-ai-insight-error` partagent la palette WordPress (gris neutres et accent bleu) en cohérence avec les directives du Customizer, tout en affichant le spinner standard (`.spinner is-active`) lorsqu’une génération est en cours.【F:sitepulse_FR/modules/ai_insights.php†L2397-L2413】【F:sitepulse_FR/modules/css/ai-insights.css†L1-L112】
- Les boutons d’action conservent leur focus visible (bordure bleue et ombre interne) grâce aux règles CSS héritées de `wp-core-ui`, confirmées après inspection dans l’inspecteur du navigateur.

## Recommandations
- Ajouter une capture d’écran ou un test visuel automatisé lors des prochaines évolutions majeures de l’UI pour prévenir les régressions de mise en forme.
- Introduire un test qui simule une réponse Gemini volumineuse afin de garantir que la limite configurable `sitepulse_ai_response_size_limit` reste respectée côté JavaScript.

