# Bloc "Aperçu du tableau de bord"

Ce bloc Gutenberg affiche une synthèse des principales cartes SitePulse (Vitesse, Uptime, Base de données et Journal d’erreurs) directement dans vos contenus.

## Fonctionnement

- Les données affichées proviennent des modules existants (Speed Analyzer, Uptime Tracker, Database Optimizer et Log Analyzer).
- Le rendu est généré côté serveur afin de refléter fidèlement les valeurs visibles dans l’interface d’administration.
- Dans l’éditeur, une prévisualisation temps réel est fournie à l’aide du composant `ServerSideRender`.

## Options du bloc

Chaque carte peut être masquée depuis la colonne latérale :

- **Vitesse** – affiche le dernier temps de traitement côté serveur et son statut associé.
- **Disponibilité** – montre le pourcentage de disponibilité calculé sur les dernières vérifications.
- **Base de données** – compare le nombre de révisions à la limite recommandée.
- **Journal d’erreurs** – résume les derniers évènements critiques détectés dans le fichier `debug.log`.

## États dégradés

Lorsque l’un des modules requis est désactivé, une notice informative s’affiche dans l’éditeur et le rendu serveur masque automatiquement la carte correspondante.

Si aucune métrique n’est disponible (par exemple sur un nouveau site), le bloc affiche un message neutre plutôt qu’un graphique vide.

## Dépendances

Pour garantir le style et l’accessibilité, le bloc réutilise les mêmes feuilles de style que le tableau de bord SitePulse (`modules/css/custom-dashboard.css`).

## Débogage d’un affichage dégradé

1. **Vérifier les feuilles de style chargées** – Dans l’inspecteur du navigateur (onglet *Réseau* ou *Éléments*), confirmez que les handles `sitepulse-dashboard-preview-style` et `sitepulse-dashboard-preview-base` sont bien présents. Sans eux, les cartes héritent des styles du thème et se chevauchent.
2. **Tester le rendu isolé** – Ouvrez le fichier `docs/visual-debug/dashboard-preview.html` inclus dans le plugin. Il charge uniquement les CSS du bloc et permet de comparer rapidement le rendu attendu avec celui du site.【F:sitepulse_FR/docs/visual-debug/dashboard-preview.html†L1-L112】
3. **Inspecter le contexte PHP** – Depuis la racine WordPress, lancez `wp eval 'var_export(sitepulse_get_dashboard_preview_context());'` pour vérifier que chaque module renvoie bien ses cartes (`speed`, `uptime`, `database`, `logs`). Un module désactivé ou sans données renvoie un tableau vide.
4. **Activer le mode debug SitePulse** – Ajoutez `define('SITEPULSE_DEBUG', true);` dans `wp-config.php` pour obtenir les notices additionnelles du plugin (incluant celles liées au chargement des modules et du bloc). Pensez à le désactiver ensuite en production.
5. **Comparer les classes générées** – Les cartes utilisent des classes spécifiques (`sitepulse-card--speed`, `sitepulse-card--uptime`, etc.). Si elles sont absentes dans le HTML final, un filtre tiers modifie le rendu : désactivez temporairement les plugins de mise en cache / optimisation HTML pour confirmer.
