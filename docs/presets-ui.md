# Presets graphiques et bibliothèques UI

Cette fiche répertorie différentes bibliothèques de composants, kits CSS et moteurs d'animation offrant des approches comparables à Headless UI, Shadcn UI, Radix UI, Bootstrap, Semantic UI et Anime.js. Chaque proposition comprend les points forts, l'écosystème ciblé et des idées d'utilisation pour SitePulse ou d'autres projets WordPress. Vous retrouvez désormais ces informations directement dans l'interface d'administration via la page **SitePulse → Presets UI** qui offre un rendu structuré et filtrable.

## Bibliothèques React/JS orientées accessibilité

### 1. **Mantine**
- **Points clés** : plus de 120 composants prêts à l'emploi, système de styles via Emotion, thèmes clairs/sombres et prise en charge du SSR (Next.js, Remix).
- **Pourquoi l'envisager** : API cohérente avec Headless UI/Radix, grande souplesse pour personnaliser les tokens de design, documentation exhaustive.
- **Intégrations** : plugins pour Datagrids, rich text editor et graphiques (via `@mantine/charts`).

### 2. **Chakra UI**
- **Points clés** : composants accessibles avec hooks d'état, design tokens configurables, support Typescript complet.
- **Pourquoi l'envisager** : proche de Shadcn (utilisation de Tailwind possible via `className`), système de theming puissant, adoption communautaire forte.
- **Intégrations** : Next.js, Gatsby, Storybook et adaptateurs pour Framer Motion.

### 3. **React Aria + React Spectrum**
- **Points clés** : fondations Headless (React Aria) avec composants stylés (React Spectrum), conformité WCAG stricte.
- **Pourquoi l'envisager** : proche de Headless UI en séparant logique et présentation, idéal pour construire des interfaces administrateur accessibles.
- **Intégrations** : thèmes par défaut Spectrum, support RTL et internationalisation.

## Kits CSS et design systems modulaires

### 4. **Tailwind UI + Flowbite**
- **Points clés** : composants pré-stylés basés sur Tailwind CSS, Flowbite ajoute des scripts pour modals, accordéons, etc.
- **Pourquoi l'envisager** : alternative à Shadcn UI pour WordPress (via `@wordpress/scripts` + Tailwind), rapide à personnaliser.
- **Intégrations** : plugins Tailwind, compatibilité avec Alpine.js et React.

### 5. **Bulma**
- **Points clés** : framework CSS flexbox, classes utilitaires intuitives, aucune dépendance JavaScript.
- **Pourquoi l'envisager** : similaire à Bootstrap/Semantic UI mais plus léger, adaptation facile dans un thème WP.
- **Intégrations** : modules Sass, extensions communautaires (`buefy` pour Vue, `bloomer` pour React).

### 6. **Foundation**
- **Points clés** : grille responsive avancée, composants CSS/JS, mixins Sass/XY Grid.
- **Pourquoi l'envisager** : alternative robuste à Bootstrap pour applications complexes, grande granularité via utilitaires.
- **Intégrations** : CLI officielle, compatibilité WooCommerce via thèmes Foundation.

## Bibliothèques web components / agnostiques framework

### 7. **Shoelace**
- **Points clés** : web components standards, thèmes personnalisables, support natif pour formulaires.
- **Pourquoi l'envisager** : comparable à Radix UI en mode framework-agnostic, parfait pour intégrer dans Gutenberg ou une SPA.
- **Intégrations** : fonctionne avec React, Vue, Angular, Lit et Vanilla JS.

### 8. **Vaadin Components**
- **Points clés** : large éventail de composants UI (grilles, formulaires, charts) basés sur web components.
- **Pourquoi l'envisager** : offre des éléments professionnels (grille de données, éditeur riche) tout en restant accessibles.
- **Intégrations** : wrappers officiels React/Vaadin Flow, thèmes Lumo/Material.

## Librairies d'animation et motion design

### 9. **GSAP (GreenSock Animation Platform)**
- **Points clés** : timeline avancée, plugins (ScrollTrigger, MorphSVG), performances optimisées.
- **Pourquoi l'envisager** : alternative principale à Anime.js, adaptée aux dashboards dynamiques.
- **Intégrations** : React (via `gsap` + `useLayoutEffect`), Vue, Vanilla et WebGL.

### 10. **Framer Motion**
- **Points clés** : API déclarative pour React/Next.js, gestuelle et animation physique, intégration avec Chakra UI & Tailwind.
- **Pourquoi l'envisager** : complément parfait à Headless UI/Shadcn pour enrichir la micro-interaction.
- **Intégrations** : Next.js, Remix, Storybook, React Server Components (mode "app").

### 11. **Motion One**
- **Points clés** : animation via l'API Web Animations, syntaxe légère, support TypeScript.
- **Pourquoi l'envisager** : alternative moderne à Anime.js pour projets Vanilla/React, idéal pour animations discrètes dans un back-office.
- **Intégrations** : compatibilité avec Svelte, Solid, Astro via adaptateurs.

## Solutions orientées WordPress / Gutenberg

### 12. **WP Components (Gutenberg)**
- **Points clés** : librairie de composants React utilisée dans l'éditeur de blocs (Button, Card, NavigableMenu, etc.).
- **Pourquoi l'envisager** : cohérence visuelle avec l'interface WordPress, accessibilité native, parfait pour extensions.
- **Intégrations** : packages `@wordpress/components`, `@wordpress/primitives` et `@wordpress/base-styles`.

### 13. **Extendify UI Kits**
- **Points clés** : collections de patterns et blocs pré-stylés pour Gutenberg, prêts à l'emploi.
- **Pourquoi l'envisager** : proche de Bootstrap/Semantic UI pour le monde Gutenberg, accélère la création de pages marketing.
- **Intégrations** : import direct dans l'éditeur de blocs, thèmes compatibles Block Theme.

### 14. **AinoBlocks**
- **Points clés** : design system basé sur Gutenberg avec variables globales (couleurs, typographies), blocs orientés UI.
- **Pourquoi l'envisager** : bonne base pour un preset design cohérent côté front et back-office.
- **Intégrations** : thèmes FSE (Full Site Editing), compatibilité WooCommerce.

---

**Conseil** : pour chaque preset, évaluez la compatibilité avec votre stack (React, Vue, Vanilla, Gutenberg), le niveau d'accessibilité requis et la possibilité de personnaliser les tokens/design tokens afin d'assurer une cohérence visuelle avec SitePulse.
