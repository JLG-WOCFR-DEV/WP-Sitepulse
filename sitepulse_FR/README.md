# Sitepulse - JLG

**Contributors:** jeromelegousse  
**Tags:** performance, monitoring, speed, database, server

Monitors your WordPress site's speed, database, maintenance, server, and errors with modular, toggleable tools.

## Description

Sitepulse - JLG takes the pulse of your WordPress site, offering modules for:

- Speed analysis (load times, server processing time)
- Database optimization (clean bloat, suggest indexes)
- Server monitoring (CPU, memory, uptime) with programmable maintenance windows that pause alerts and keep an internal audit trail
- Error logging and alerts
- Plugin impact analysis
- Maintenance checks and AI insights
- Custom dashboards and multisite support
- Customisable thresholds for speed alerts, uptime targets and revision cleanup suggestions
- Site Health integration surfacing SitePulse alerts and AI requirements
- Accessible sharing tools for AI recommendations (CSV export, clipboard copy, contextual notes)

### Key performance defaults

- **Alerte de vitesse (avertissement / critique)** : 200 ms / 500 ms tant que vous n’avez pas défini vos propres seuils.
- **Disponibilité minimale avant alerte** : 99 % par défaut, ajustable au dixième de point près.
- **Limite de révisions recommandée** : 100 révisions avant que SitePulse ne signale un nettoyage.

Ces valeurs peuvent être modifiées dans la page « Réglages » de SitePulse. Elles sont automatiquement utilisées par les modules concernés (analyseur de vitesse, tableaux de bord personnalisés, vérification de base de données) tout en conservant les anciennes valeurs si elles existent déjà.

Toggle modules in the admin panel to keep it lightweight. Includes debug mode and cleanup options.

## Installation

1. Upload `sitepulse-jlg.zip` to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit 'SitePulse' in your admin menu to configure the modules.

## Diagnostic « Santé du site »

SitePulse enregistre désormais deux tests visibles dans l’outil « Santé du site » de WordPress :

- **État de SitePulse** récapitule les avertissements WP-Cron et les erreurs critiques générées par l’IA, en les classant selon leur gravité.
- **Clé API Gemini SitePulse** vérifie que le module AI Insights dispose d’une clé API prête à l’emploi avant de lancer des analyses.

Ces tests facilitent la détection proactive des problèmes susceptibles d’empêcher l’exécution des tâches planifiées ou des analyses IA.

## Exporter et partager les recommandations IA

Depuis la page **Analyses par IA**, la section « Historique des recommandations » répertorie les réponses générées et propose plusieurs actions accessibles :

- Filtrez les recommandations par modèle ou limitation avant d’exporter.
- Cliquez sur **Exporter en CSV** pour télécharger un fichier structuré (format UTF-8, séparateur « ; ») contenant la date, le modèle, la limitation, le texte et vos éventuelles notes personnelles.
- Cliquez sur **Copier** pour envoyer le même contenu (y compris les notes) dans le presse-papiers. SitePulse prépare automatiquement un texte brut et utilise l’API du presse-papiers quand elle est disponible, avec une annonce via `aria-live` pour les lecteurs d’écran.
- Chaque élément d’historique propose un champ « Note personnelle ». Les commentaires saisis sont enregistrés en base via une option dédiée et sont inclus dans les exports ultérieurs.

Les actions d’export et de copie prennent en compte vos filtres actifs : seules les recommandations visibles sont partagées. Un message de confirmation accessible informe systématiquement du résultat (succès ou erreur) pour garantir une expérience inclusive.

## Sécuriser la clé API Gemini

Pour éviter de stocker votre clé API Gemini dans la base de données, vous pouvez la définir directement dans `wp-config.php` :

```php
define('SITEPULSE_GEMINI_API_KEY', 'votre-cle-secrete');
```

Lorsque cette constante est présente, SitePulse utilise automatiquement cette valeur, désactive le champ de saisie dans l’interface d’administration et ignore les tentatives d’enregistrement. Vous pouvez également fournir la clé dynamiquement via le filtre `sitepulse_gemini_api_key` si vous la récupérez depuis un gestionnaire de secrets :

```php
add_filter('sitepulse_gemini_api_key', function () {
    return getenv('SITEPULSE_GEMINI_API_KEY');
});
```

Dans les deux cas, aucune donnée sensible n’est conservée dans la base WordPress.

## WordPress Compatibility

- Requires at least: 5.0
- Tested up to: 6.6
- Stable tag: 1.0

## License

GPLv2 or later  
[http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

## Changelog

### 1.0

- Initial release with all core modules.

## Upgrade Notice

### 1.0

- First version—full pulse-monitoring suite!

