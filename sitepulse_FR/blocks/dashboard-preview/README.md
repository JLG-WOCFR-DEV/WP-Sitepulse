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
