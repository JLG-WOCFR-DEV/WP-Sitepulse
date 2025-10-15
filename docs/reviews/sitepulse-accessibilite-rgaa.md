# Revue accessibilité RGAA & fiabilité debug – SitePulse

## Points critiques

### 1. Chaîne plurielle non localisée correctement
Le compteur dynamique de la navigation modules remplace uniquement le premier jeton `%d` dans la chaîne `data-plural`. Les traductions qui utilisent un emplacement numéroté (`%1$d`) ou plusieurs occurrences conserveront le placeholder visible (« %1$d modules affichés »), ce qui nuit à la compréhension pour les lecteurs d’écran et enfreint les exigences RGAA relatives aux libellés compréhensibles.【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L63-L148】 Utilisez plutôt `wp.i18n.sprintf` (ou un équivalent maison qui gère `%1$s`) afin de respecter les pluralisations localisées.

### 2. File d’attente de notices debug non bornée
Lorsqu’on planifie des notices côté front (`SITEPULSE_DEBUG` actif), chaque message unique est ajouté à l’option `sitepulse_debug_notices` sans limite ni purge intermédiaire. Une rafale d’erreurs variées peut donc générer un tableau volumineux stocké en base jusqu’à la prochaine visite d’un administrateur, ce qui contrevient aux bonnes pratiques RGAA sur la stabilité des aides techniques (risque de lenteurs, voire de dépassement mémoire lors du rendu des notices).【F:sitepulse_FR/includes/debug-notices.php†L57-L183】 Ajoutez un plafond (ex. 20 entrées) ou compressez les doublons pour éviter l’accumulation.

## Points positifs

- Les graphiques du bloc « Dashboard preview » exposent un résumé textuel et un fallback vocalisé (`aria-describedby` / `aria-label`) qui répond aux critères RGAA 1.1 et 4.1 pour le contenu non textuel.【F:sitepulse_FR/blocks/dashboard-preview/render.php†L120-L194】
- Le sélecteur de modules embarque un formulaire de recherche étiqueté, des compteurs `role="status"` et des commandes clavier avec styles de focus visibles, garantissant la conformité aux règles 7.1 et 8.9.【F:sitepulse_FR/includes/module-selector.php†L512-L672】【F:sitepulse_FR/modules/css/module-navigation.css†L20-L258】
- La visionneuse d’images crée un vrai dialogue modal (`role="dialog"`, focus trap, gestion Escape) et annonce les contrôles en `aria`, ce qui sécurise l’usage clavier/lecteurs d’écran conformément aux tests RGAA 7.1 et 10.10.【F:sitepulse_FR/modules/js/sitepulse-article-slideshow.js†L378-L457】【F:sitepulse_FR/modules/js/sitepulse-article-slideshow.js†L700-L798】
- La palette de couleurs claire/sombre définit explicitement les contrastes et variations d’état, facilitant le respect des ratios RGAA 3.2/3.3 sans dépendre d’une seule couleur.【F:sitepulse_FR/modules/css/sitepulse-theme.css†L1-L120】
- Le module debug bénéficie d’une couverture test unitaire qui vérifie la mise en file d’attente, l’affichage unique et la purge, réduisant le risque de régressions côté notifications administrateur.【F:sitepulse_FR/tests/sitepulse_debug_notices_test.php†L76-L121】

## Recommandations

- Aucune recommandation ouverte à ce jour. Les contrôles doivent toutefois rester suivis à travers la grille d’audit RGAA dédiée.

## Suivi des correctifs

1. La navigation des modules exploite désormais `wp.i18n.sprintf` lorsqu’il est disponible afin de formatter correctement les pluriels et placeholders numérotés, avec un repli robuste en cas d’absence du bundle i18n, et le script déclare sa dépendance à `wp-i18n` pour garantir le chargement de l’API de localisation côté WordPress.【F:sitepulse_FR/includes/module-selector.php†L302-L331】【F:sitepulse_FR/modules/custom_dashboards.php†L115-L152】【F:sitepulse_FR/modules/js/sitepulse-dashboard-nav.js†L1-L109】
2. La file d’attente des notices debug est plafonnée (par défaut à 20 entrées) et produit un avertissement via `error_log` dès que la limite est atteinte, limitant ainsi l’impact mémoire tout en conservant les messages récents.【F:sitepulse_FR/includes/debug-notices.php†L1-L164】
3. La documentation produit intègre désormais une grille d’audit RGAA couvrant contrastes, navigation clavier, alternatives textuelles et notifications dynamiques pour guider les vérifications récurrentes.【F:docs/rgaa-audit-checklist.md†L1-L33】
4. Les notices de débogage administrateur exposent des attributs ARIA (`role`, `aria-live`, `aria-atomic`) adaptés à leur sévérité afin de garantir l’annonce correcte des messages par les lecteurs d’écran et de respecter les critères RGAA relatifs aux changements de contexte dynamiques.【F:sitepulse_FR/includes/debug-notices.php†L1-L221】【F:sitepulse_FR/tests/sitepulse_debug_notices_test.php†L1-L140】
