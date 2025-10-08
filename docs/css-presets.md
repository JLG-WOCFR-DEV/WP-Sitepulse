# Présentations CSS pour SitePulse

SitePulse charge désormais une feuille de style dédiée aux presets visuels afin d’aligner l’interface principale, le widget WordPress et le bloc « Dashboard preview » sur votre identité graphique. La présentation WordPress par défaut reste disponible et trois variantes additionnelles peuvent être activées sans modifier le cœur du plugin.

## Résumé des presets

| Slug | Nom | Usage recommandé |
| --- | --- | --- |
| `default` | Présentation WordPress | Palette claire d’origine : idéal lorsque vous souhaitez conserver la charte WordPress standard. |
| `soft-mint` | Soft Mint | Interface pastel très lisible pour les environnements calmes ou les tableaux de bord orientés clients. |
| `midnight` | Midnight | Thème sombre inspiré des consoles d’observabilité, parfait dans les salles de supervision ou pour réduire la fatigue visuelle. |
| `contrast` | Contrast Pro | Palette extrêmement contrastée répondant aux exigences WCAG AA/AAA dans des environnements très lumineux. |

Chaque preset aligne :

- les cartes (`.sitepulse-card`), l’en-tête synthèse et la grille KPI ;
- les bandeaux de statut (OK, Attention, Danger, Info) ;
- le widget « purges de transients » sur le tableau de bord WordPress ;
- le bloc Gutenberg « Dashboard preview » ainsi que ses placeholders graphiques et boutons.

## Activer un preset global pour l’administration

Le plugin expose le filtre `sitepulse_active_css_preset`. Retournez le slug désiré (voir tableau ci-dessus) pour appliquer automatiquement la classe `sitepulse-appearance--{slug}` et charger les styles correspondants sur toutes les pages SitePulse ainsi que sur le tableau de bord WordPress.

```php
<?php
// functions.php ou plugin compagnon.
add_filter('sitepulse_active_css_preset', function ($current, $catalog) {
    // Vérifie que le preset est bien référencé avant de l’activer.
    if (isset($catalog['midnight'])) {
        return 'midnight';
    }

    return $current; // Fallback si le preset est indisponible.
}, 10, 2);
```

> 💡 Les variables `--wp-admin-theme-color` sont ajustées pour chaque preset, ce qui harmonise également les boutons natifs (`.button-primary`) et les focus rings WordPress.

## Appliquer un preset sur le bloc « Dashboard preview »

Dans l’éditeur de blocs, ouvrez l’onglet **Avancé** de la colonne latérale et ajoutez la classe souhaitée dans le champ « Classe(s) CSS additionnelle(s) ».

Exemple pour la variante sombre :

```
sitepulse-appearance--midnight
```

La classe peut aussi être appliquée sur un conteneur parent si vous souhaitez styliser plusieurs blocs SitePulse simultanément.

## Utiliser les presets dans des widgets personnalisés

Les presets s’appuient sur les mêmes couleurs et ombrages que le widget natif `sitepulse_transient_purge_widget`. Lorsque la classe `sitepulse-appearance--{slug}` est présente sur `body`, toutes les autres boîtes que vous ajoutez dans le tableau de bord peuvent reprendre cette identité visuelle en ciblant les variables CSS exposées :

```css
.my-sitepulse-widget {
    background: var(--sitepulse-surface, #fff);
    border: 1px solid var(--sitepulse-border, #dcdcde);
    color: var(--sitepulse-text, #1d2327);
    box-shadow: var(--sitepulse-widget-shadow, 0 1px 1px rgba(0, 0, 0, 0.04));
}
```

## Personnaliser ou étendre les presets

- Dupliquez les sélecteurs présents dans `modules/css/appearance-presets.css` pour créer votre propre déclinaison (ex. `sitepulse-appearance--brand`) puis renvoyez le slug via le filtre.
- Pour une bascule contextualisée (en fonction de l’utilisateur, d’un environnement ou d’un horaire), utilisez le même filtre et retournez dynamiquement le slug voulu.
- Les presets n’écrasent pas les styles existants : si un module définit des couleurs personnalisées, il suffit de prolonger les règles dans un plugin compagnon ou un mu-plugin.

En combinant ces presets et vos propres règles, vous pouvez proposer une interface SitePulse cohérente avec votre charte graphique tout en gardant la maintenance centralisée.
