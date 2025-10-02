# Sitepulse - JLG

**Contributors:** jeromelegousse  
**Tags:** performance, monitoring, speed, database, server

Monitors your WordPress site's speed, database, maintenance, server, and errors with modular, toggleable tools.

## Description

Sitepulse - JLG takes the pulse of your WordPress site, offering modules for:

- Speed analysis (load times, server processing time)
- Database optimization (clean bloat, suggest indexes)
- Server monitoring (CPU, memory, uptime)
- Error logging and alerts
- Plugin impact analysis
- Maintenance checks and AI insights
- Custom dashboards and multisite support
- Customisable thresholds for speed alerts, uptime targets and revision cleanup suggestions

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

