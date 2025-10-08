<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the catalog categories for UI presets.
 *
 * @return array<string, array{label:string,description:string}>
 */
function sitepulse_get_ui_preset_categories() {
    return [
        'react_accessibility' => [
            'label'       => __('Bibliothèques React/JS orientées accessibilité', 'sitepulse'),
            'description' => __('Composants headless ou stylés pensés pour des interfaces accessibles et personnalisables.', 'sitepulse'),
        ],
        'css_kits' => [
            'label'       => __('Kits CSS et design systems modulaires', 'sitepulse'),
            'description' => __('Frameworks CSS prêts à l’emploi ou hybrides pour accélérer les maquettes responsives.', 'sitepulse'),
        ],
        'web_components' => [
            'label'       => __('Bibliothèques web components / framework-agnostic', 'sitepulse'),
            'description' => __('Solutions compatibles avec plusieurs stacks (React, Vue, Vanilla) reposant sur les Web Components.', 'sitepulse'),
        ],
        'animation' => [
            'label'       => __('Librairies d’animation et motion design', 'sitepulse'),
            'description' => __('Outils pour orchestrer les micro-interactions et les transitions complexes.', 'sitepulse'),
        ],
        'wordpress' => [
            'label'       => __('Solutions orientées WordPress / Gutenberg', 'sitepulse'),
            'description' => __('Kits et blocs prêts à intégrer dans l’éditeur ou un thème Full Site Editing.', 'sitepulse'),
        ],
    ];
}

/**
 * Returns the catalog of UI presets with strengths and integration guidance.
 *
 * @return array<string, array{
 *     name:string,
 *     url:string,
 *     category:string,
 *     ecosystem:string,
 *     summary:string,
 *     strengths:array<int, string>,
 *     adoption:string,
 *     integration:string
 * }>
 */
function sitepulse_get_ui_presets_catalog() {
    return [
        'mantine' => [
            'name'        => 'Mantine',
            'url'         => 'https://mantine.dev/',
            'category'    => 'react_accessibility',
            'ecosystem'   => 'React · Vite · SSR',
            'summary'     => __('Suite de plus de 120 composants React stylés via Emotion avec thèmes clair/sombre.', 'sitepulse'),
            'strengths'   => [
                __('Composants accessibles avec gestion d’état avancée.', 'sitepulse'),
                __('Design tokens modulables et support complet de TypeScript.', 'sitepulse'),
            ],
            'adoption'    => __('API cohérente avec Headless UI et Radix pour créer des interfaces administrateur sur mesure.', 'sitepulse'),
            'integration' => __('Intégrez Mantine avec Next.js ou Remix pour bâtir les écrans SitePulse en SSR, et exploitez `@mantine/charts` pour les dashboards.', 'sitepulse'),
        ],
        'chakra_ui' => [
            'name'        => 'Chakra UI',
            'url'         => 'https://chakra-ui.com/',
            'category'    => 'react_accessibility',
            'ecosystem'   => 'React · TypeScript · Emotion',
            'summary'     => __('Composants accessibles et hookés avec système de theming complet.', 'sitepulse'),
            'strengths'   => [
                __('Design tokens centralisés et mode sombre natif.', 'sitepulse'),
                __('Interopérable avec Tailwind via `className` et Framer Motion.', 'sitepulse'),
            ],
            'adoption'    => __('Permet de prototyper rapidement une interface proche de Shadcn UI tout en conservant un contrôle granulaire.', 'sitepulse'),
            'integration' => __('Couplez Chakra UI avec Storybook ou Next.js pour documenter vos composants SitePulse et animer les interactions via Framer Motion.', 'sitepulse'),
        ],
        'react_spectrum' => [
            'name'        => 'React Aria + React Spectrum',
            'url'         => 'https://react-spectrum.adobe.com/',
            'category'    => 'react_accessibility',
            'ecosystem'   => 'React · Headless · TypeScript',
            'summary'     => __('Fondations headless (React Aria) assorties de composants stylés Spectrum.', 'sitepulse'),
            'strengths'   => [
                __('Respect strict des recommandations WCAG et support RTL.', 'sitepulse'),
                __('Séparation logique / présentation pour construire vos propres thèmes.', 'sitepulse'),
            ],
            'adoption'    => __('Idéal pour créer des modules back-office accessibles partageant une logique headless avec votre design system.', 'sitepulse'),
            'integration' => __('Mappez les hooks React Aria sur vos composants Gutenberg personnalisés pour conserver l’accessibilité tout en maîtrisant le style.', 'sitepulse'),
        ],
        'tailwind_flowbite' => [
            'name'        => 'Tailwind UI + Flowbite',
            'url'         => 'https://flowbite.com/',
            'category'    => 'css_kits',
            'ecosystem'   => 'Tailwind CSS · HTML · JS',
            'summary'     => __('Composants pré-stylés Tailwind avec scripts d’interaction pour les éléments dynamiques.', 'sitepulse'),
            'strengths'   => [
                __('Large catalogue de patterns Tailwind adaptables en quelques classes.', 'sitepulse'),
                __('Flowbite fournit les scripts pour modales, carrousels et menus.', 'sitepulse'),
            ],
            'adoption'    => __('Alternative clé en main à Shadcn UI pour accélérer les interfaces WordPress propulsées par Tailwind.', 'sitepulse'),
            'integration' => __('Activez Tailwind via `@wordpress/scripts` et chargez les composants Flowbite dans l’interface SitePulse pour des écrans cohérents.', 'sitepulse'),
        ],
        'bulma' => [
            'name'        => 'Bulma',
            'url'         => 'https://bulma.io/',
            'category'    => 'css_kits',
            'ecosystem'   => 'CSS pur · Sass',
            'summary'     => __('Framework CSS responsive basé sur Flexbox sans dépendance JavaScript.', 'sitepulse'),
            'strengths'   => [
                __('Système de colonnes intuitif et composants légers.', 'sitepulse'),
                __('Extensions Sass nombreuses pour adapter un thème WordPress.', 'sitepulse'),
            ],
            'adoption'    => __('Solution légère si vous souhaitez une alternative à Bootstrap/Semantic UI pour des interfaces admin.', 'sitepulse'),
            'integration' => __('Compilez Bulma avec vos variables WP et servez-le via l’admin enqueue pour styliser rapidement vos écrans personnalisés.', 'sitepulse'),
        ],
        'foundation' => [
            'name'        => 'Foundation',
            'url'         => 'https://get.foundation/',
            'category'    => 'css_kits',
            'ecosystem'   => 'CSS · Sass · JavaScript',
            'summary'     => __('Grille avancée, composants UI et utilitaires Sass pour projets complexes.', 'sitepulse'),
            'strengths'   => [
                __('XY Grid puissant pour orchestrer des layouts responsive.', 'sitepulse'),
                __('Composants JavaScript modulaires et thèmes personnalisables.', 'sitepulse'),
            ],
            'adoption'    => __('Utile pour reproduire la granularité de Bootstrap avec plus de contrôle sur les breakpoints.', 'sitepulse'),
            'integration' => __('Servez Foundation via un thème enfant SitePulse ou un plugin mu et personnalisez les mixins Sass pour vos tableaux de bord.', 'sitepulse'),
        ],
        'shoelace' => [
            'name'        => 'Shoelace',
            'url'         => 'https://shoelace.style/',
            'category'    => 'web_components',
            'ecosystem'   => 'Web Components · CSS Custom Properties',
            'summary'     => __('Bibliothèque de Web Components standards avec thèmes personnalisables.', 'sitepulse'),
            'strengths'   => [
                __('Fonctionne sans framework et supporte nativement les formulaires.', 'sitepulse'),
                __('Styles ajustables via CSS Custom Properties et thèmes.', 'sitepulse'),
            ],
            'adoption'    => __('Parfait pour partager des composants entre Gutenberg et des apps front (React, Vue, Vanilla).', 'sitepulse'),
            'integration' => __('Chargez les bundles Shoelace dans l’admin et enregistrez les composants dans vos blocs pour un rendu cohérent.', 'sitepulse'),
        ],
        'vaadin' => [
            'name'        => 'Vaadin Components',
            'url'         => 'https://vaadin.com/components',
            'category'    => 'web_components',
            'ecosystem'   => 'Web Components · Lit · Java',
            'summary'     => __('Large gamme de composants professionnels (grilles, formulaires, charts).', 'sitepulse'),
            'strengths'   => [
                __('Composants riches comme data grid, date picker ou rich text.', 'sitepulse'),
                __('Thèmes Material et Lumo prêts à l’emploi.', 'sitepulse'),
            ],
            'adoption'    => __('À privilégier pour des interfaces complexes nécessitant des composants enterprise accessibles.', 'sitepulse'),
            'integration' => __('Utilisez les wrappers React ou servez les web components directement dans Gutenberg via `@vaadin/web-components`.', 'sitepulse'),
        ],
        'gsap' => [
            'name'        => 'GSAP',
            'url'         => 'https://greensock.com/gsap/',
            'category'    => 'animation',
            'ecosystem'   => 'JavaScript · Web Animations · Canvas',
            'summary'     => __('Plateforme d’animation avec timeline avancée et nombreux plugins.', 'sitepulse'),
            'strengths'   => [
                __('Performances optimisées pour animer des dashboards denses.', 'sitepulse'),
                __('Plugins comme ScrollTrigger ou MorphSVG pour scénariser vos vues.', 'sitepulse'),
            ],
            'adoption'    => __('Référence pour remplacer Anime.js lorsqu’il faut orchestrer des animations complexes.', 'sitepulse'),
            'integration' => __('Initialisez GSAP sur vos pages admin via `useLayoutEffect` (React) ou hooks WP pour synchroniser les transitions SitePulse.', 'sitepulse'),
        ],
        'framer_motion' => [
            'name'        => 'Framer Motion',
            'url'         => 'https://www.framer.com/motion/',
            'category'    => 'animation',
            'ecosystem'   => 'React · TypeScript',
            'summary'     => __('API déclarative pour animations React avec gestuelle et physique réaliste.', 'sitepulse'),
            'strengths'   => [
                __('Animations conditionnelles simples via props et variants.', 'sitepulse'),
                __('Compatibilité Next.js, Remix et React Server Components.', 'sitepulse'),
            ],
            'adoption'    => __('Complément idéal à Headless UI/Shadcn pour ajouter des micro-interactions cohérentes.', 'sitepulse'),
            'integration' => __('Enrichissez les presets SitePulse avec des `motion.div` et connectez les animations aux états de vos modules.', 'sitepulse'),
        ],
        'motion_one' => [
            'name'        => 'Motion One',
            'url'         => 'https://motion.dev/',
            'category'    => 'animation',
            'ecosystem'   => 'Web Animations API · TypeScript',
            'summary'     => __('Mini-bibliothèque s’appuyant sur l’API Web Animations avec syntaxe légère.', 'sitepulse'),
            'strengths'   => [
                __('Bundle très léger pour des animations discrètes.', 'sitepulse'),
                __('Adaptateurs pour Svelte, Solid et Astro.', 'sitepulse'),
            ],
            'adoption'    => __('Alternative moderne à Anime.js pour garder des interactions fluides sans surcharge.', 'sitepulse'),
            'integration' => __('Servez Motion One dans les modules où la taille du bundle est critique et coordonnez les séquences via les utilitaires timeline.', 'sitepulse'),
        ],
        'wp_components' => [
            'name'        => __('WP Components (Gutenberg)', 'sitepulse'),
            'url'         => 'https://developer.wordpress.org/block-editor/reference-guides/components/',
            'category'    => 'wordpress',
            'ecosystem'   => 'React · WordPress',
            'summary'     => __('Bibliothèque officielle utilisée dans l’éditeur de blocs.', 'sitepulse'),
            'strengths'   => [
                __('Garantit la cohérence visuelle avec l’administration WordPress.', 'sitepulse'),
                __('Accessibilité native et nombreux composants formulaires.', 'sitepulse'),
            ],
            'adoption'    => __('La solution de base pour des modules SitePulse parfaitement intégrés à Gutenberg.', 'sitepulse'),
            'integration' => __('Reposez-vous sur `@wordpress/components`, `@wordpress/primitives` et `@wordpress/base-styles` pour profiter du style natif.', 'sitepulse'),
        ],
        'extendify' => [
            'name'        => 'Extendify UI Kits',
            'url'         => 'https://extendify.com/',
            'category'    => 'wordpress',
            'ecosystem'   => 'Gutenberg · Pattern Library',
            'summary'     => __('Collections de patterns et blocs Gutenberg prêts à l’emploi.', 'sitepulse'),
            'strengths'   => [
                __('Large choix de sections marketing et layouts responsive.', 'sitepulse'),
                __('Import direct depuis l’éditeur pour gagner du temps.', 'sitepulse'),
            ],
            'adoption'    => __('Accélère la création de pages marketing sans repartir de zéro.', 'sitepulse'),
            'integration' => __('Synchronisez vos presets Extendify avec les variables globales SitePulse pour conserver un branding homogène.', 'sitepulse'),
        ],
        'ainoblocks' => [
            'name'        => 'AinoBlocks',
            'url'         => 'https://ainoblocks.io/',
            'category'    => 'wordpress',
            'ecosystem'   => 'Gutenberg · FSE',
            'summary'     => __('Design system basé sur Gutenberg avec variables globales.', 'sitepulse'),
            'strengths'   => [
                __('Propose des patterns cohérents pour front et back-office.', 'sitepulse'),
                __('Compatible WooCommerce et thèmes Full Site Editing.', 'sitepulse'),
            ],
            'adoption'    => __('Bonne base pour bâtir un preset design unifié côté site et dashboards.', 'sitepulse'),
            'integration' => __('Connectez les presets AinoBlocks aux options SitePulse (couleurs, typographies) pour une expérience alignée.', 'sitepulse'),
        ],
    ];
}

/**
 * Registers the UI presets submenu under the SitePulse menu.
 *
 * @return void
 */
function sitepulse_register_ui_presets_menu() {
    add_submenu_page(
        'sitepulse-dashboard',
        __('Presets UI recommandés', 'sitepulse'),
        __('Presets UI', 'sitepulse'),
        sitepulse_get_capability(),
        'sitepulse-ui-presets',
        'sitepulse_render_ui_presets_page',
        40
    );
}
add_action('admin_menu', 'sitepulse_register_ui_presets_menu');

/**
 * Enqueues assets for the UI presets page when needed.
 *
 * @param string $hook_suffix Current admin page identifier.
 * @return void
 */
function sitepulse_ui_presets_enqueue_assets($hook_suffix) {
    $allowed_hooks = [
        'sitepulse-dashboard_page_sitepulse-ui-presets',
    ];

    if (!in_array($hook_suffix, $allowed_hooks, true)) {
        return;
    }

    $handle = 'sitepulse-ui-presets';
    $src    = SITEPULSE_URL . 'modules/css/ui-presets.css';
    $deps   = [];
    $ver    = defined('SITEPULSE_VERSION') ? SITEPULSE_VERSION : false;

    if (!wp_style_is($handle, 'registered')) {
        wp_register_style($handle, $src, $deps, $ver);
    }

    wp_enqueue_style($handle);
}
add_action('admin_enqueue_scripts', 'sitepulse_ui_presets_enqueue_assets');

/**
 * Adds the UI presets page to the module selector navigation.
 *
 * @param array<string, array<string, mixed>> $definitions Existing module definitions.
 * @return array<string, array<string, mixed>>
 */
function sitepulse_register_ui_presets_selector($definitions) {
    $definitions['ui_presets'] = [
        'page'             => 'sitepulse-ui-presets',
        'label'            => __('Presets UI', 'sitepulse'),
        'icon'             => 'dashicons-art',
        'tags'             => ['design', 'frontend', 'pattern'],
        'always_available' => true,
    ];

    return $definitions;
}
add_filter('sitepulse_module_selector_definitions', 'sitepulse_register_ui_presets_selector');

/**
 * Renders the UI presets admin page.
 *
 * @return void
 */
function sitepulse_render_ui_presets_page() {
    if (!current_user_can(sitepulse_get_capability())) {
        wp_die(esc_html__("Vous n'avez pas les permissions nécessaires pour accéder à cette page.", 'sitepulse'));
    }

    $categories = sitepulse_get_ui_preset_categories();
    $catalog    = sitepulse_get_ui_presets_catalog();

    $grouped = [];

    foreach ($catalog as $slug => $preset) {
        $category = isset($preset['category']) ? (string) $preset['category'] : '';

        if ($category === '') {
            $category = 'other';
        }

        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }

        $grouped[$category][$slug] = $preset;
    }

    $current_page = 'sitepulse-ui-presets';
    $navigation   = function_exists('sitepulse_get_module_navigation_items')
        ? sitepulse_get_module_navigation_items($current_page)
        : [];

    echo '<div class="wrap sitepulse-ui-presets-page">';
    echo '<h1>' . esc_html__('Presets UI recommandés pour SitePulse', 'sitepulse') . '</h1>';
    echo '<p class="sitepulse-ui-presets__intro">' . esc_html__('Identifiez rapidement les bibliothèques et kits graphiques compatibles avec vos workflows SitePulse (React, Tailwind, Gutenberg ou animation). Chaque fiche rassemble les points forts et des idées d’intégration.', 'sitepulse') . '</p>';

    if (!empty($navigation)) {
        echo '<div class="sitepulse-ui-presets__nav">';
        sitepulse_render_module_navigation($current_page, $navigation);
        echo '</div>';
    }

    echo '<div class="sitepulse-ui-presets__categories">';

    foreach ($categories as $category_key => $category) {
        if (empty($grouped[$category_key])) {
            continue;
        }

        $section_id = function_exists('sanitize_title')
            ? sanitize_title($category_key)
            : preg_replace('/[^a-z0-9_-]+/i', '-', (string) $category_key);
        $section_id = trim((string) $section_id, '-');

        echo '<section class="sitepulse-ui-presets__section" id="' . esc_attr($section_id) . '">';
        echo '<header class="sitepulse-ui-presets__section-header">';
        echo '<h2>' . esc_html($category['label']) . '</h2>';

        if (!empty($category['description'])) {
            echo '<p class="sitepulse-ui-presets__section-description">' . esc_html($category['description']) . '</p>';
        }

        echo '</header>';
        echo '<div class="sitepulse-ui-presets__grid">';

        foreach ($grouped[$category_key] as $preset) {
            $name      = isset($preset['name']) ? (string) $preset['name'] : '';
            $url       = isset($preset['url']) ? (string) $preset['url'] : '';
            $ecosystem = isset($preset['ecosystem']) ? (string) $preset['ecosystem'] : '';
            $summary   = isset($preset['summary']) ? (string) $preset['summary'] : '';
            $strengths = isset($preset['strengths']) && is_array($preset['strengths']) ? $preset['strengths'] : [];
            $adoption  = isset($preset['adoption']) ? (string) $preset['adoption'] : '';
            $integration = isset($preset['integration']) ? (string) $preset['integration'] : '';

            echo '<article class="sitepulse-ui-presets__card">';
            echo '<div class="sitepulse-ui-presets__card-header">';

            if ($url !== '') {
                echo '<h3 class="sitepulse-ui-presets__card-title"><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($name) . '</a></h3>';
            } else {
                echo '<h3 class="sitepulse-ui-presets__card-title">' . esc_html($name) . '</h3>';
            }

            if ($ecosystem !== '') {
                echo '<p class="sitepulse-ui-presets__card-ecosystem">' . esc_html($ecosystem) . '</p>';
            }

            echo '</div>';

            if ($summary !== '') {
                echo '<p class="sitepulse-ui-presets__card-summary">' . esc_html($summary) . '</p>';
            }

            if (!empty($strengths)) {
                echo '<ul class="sitepulse-ui-presets__card-strengths">';

                foreach ($strengths as $strength) {
                    if ($strength === '') {
                        continue;
                    }

                    echo '<li>' . esc_html($strength) . '</li>';
                }

                echo '</ul>';
            }

            if ($adoption !== '') {
                echo '<div class="sitepulse-ui-presets__card-block">';
                echo '<h4>' . esc_html__('Pourquoi l’envisager ?', 'sitepulse') . '</h4>';
                echo '<p>' . esc_html($adoption) . '</p>';
                echo '</div>';
            }

            if ($integration !== '') {
                echo '<div class="sitepulse-ui-presets__card-block">';
                echo '<h4>' . esc_html__('Pistes d’intégration', 'sitepulse') . '</h4>';
                echo '<p>' . esc_html($integration) . '</p>';
                echo '</div>';
            }

            echo '</article>';
        }

        echo '</div>';
        echo '</section>';
    }

    echo '</div>';
    echo '</div>';
}
