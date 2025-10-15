# Grille d'audit accessibilité RGAA – SitePulse

Cette grille recense les vérifications RGAA prioritaires à exécuter à chaque évolution produit du plugin SitePulse. Elle complète la documentation produit existante afin d'assurer la conformité continue de l'interface et des modules personnalisés.

## Méthodologie
- **Périmètre** : tableau de bord SitePulse (modules navigation, KPI, notices debug), blocs Gutenberg associés et pages de configuration.
- **Support** : tests sur les navigateurs desktop majoritaires (Chrome, Firefox, Edge) avec lecteur d'écran (NVDA/JAWS) et navigation clavier seule.
- **Fréquence** : à valider à chaque release mineure du plugin et lors des refontes UI/UX majeures.
- **Critères de conformité** : se référer aux exigences RGAA 4.1 (avril 2023) et aux WCAG 2.1 niveau AA.

## Checklist détaillée

| Catégorie | Étapes de vérification | Modules concernés | Outils recommandés |
| --- | --- | --- | --- |
| Contrastes & couleurs | Vérifier que chaque texte et icône conserve un ratio ≥ 4,5:1 en mode clair et sombre. Tester les états `:hover`, `:focus`, `:disabled` des boutons de navigation et du module selector. | Module selector, dashboard KPI, bannière d'alertes | Color Contrast Analyser, axe DevTools |
| Navigation clavier | S'assurer que l'ordre de tabulation suit la logique visuelle. Tester la navigation des carrousels (`prev`/`next`), la recherche module et les modals (slideshow, réglages). | Module selector, slideshow, debug notices | NVDA (mode focus), Keyboard Testing |
| Focus visible | Vérifier la présence d'indicateurs de focus perceptibles sur tous les contrôles interactifs, y compris les boutons dans les notices debug et les CTA du dashboard. | Dashboard, debug notices, Gutenberg blocks | Capture vidéo + revue design tokens |
| Lecteurs d'écran | Contrôler les annonces des libellés (`aria-label`, `aria-describedby`) pour les KPIs, les messages d'état (`role="status"`), les modals et la navigation horizontale des modules. | Dashboard preview block, module navigation, debug notices | NVDA, JAWS, VoiceOver |
| Alternatives textuelles | Valider que chaque média (graphique, image, icône décorative) possède une alternative pertinente (`alt`, description textuelle, `aria-hidden="true"`). | Dashboard preview, articles, modules médias | WAVE, Inspection DOM |
| Dynamique & actualisation | Tester la mise à jour en direct des compteurs (résultats de recherche, notices debug) pour garantir qu'ils notifient correctement via ARIA aux lecteurs d'écran et ne provoquent pas de mouvement excessif. | Module navigation, debug notices | NVDA (mode browse), Scroll animations |
| Erreurs & notifications | Déclencher des erreurs contrôlées pour vérifier l'affichage dans la file debug (limite, messages) et leur accessibilité (`role`, `aria-live`). | Debug notices, formulaires de configuration | Console WordPress, WP-CLI |
| Responsivité | Vérifier le comportement des modules en viewport réduit (>=320px) : absence de scroll horizontal, maintien des libellés, accessibilité des CTA tactiles. | Dashboard mobile, module selector mobile | Responsive Design Mode, BrowserStack |
| Performance perçue | Contrôler l'activation de `prefers-reduced-motion` et la désactivation des animations superflues. Vérifier la taille des bundles d'accessibilité (scripts i18n). | Module navigation, animations dashboard | Lighthouse, DevTools |

## Suivi & traçabilité
- Enregistrer chaque session d'audit dans le changelog interne (date, version testée, auditeurs, outils, non-conformités détectées).
- Documenter les écarts et plans d'action dans `docs/reviews/sitepulse-accessibilite-rgaa.md` afin de garder une trace des corrections mises en œuvre.
- Archiver les captures d'écran ou exports WAVE/NVDA pour justifier la conformité ou les écarts résiduels.

## Ressources complémentaires
- [Référentiel RGAA 4.1](https://accessibilite.numerique.gouv.fr/)
- [Checklist WCAG 2.1 AA](https://www.w3.org/WAI/WCAG21/quickref/)
- [Guide WordPress Accessibility Handbook](https://make.wordpress.org/accessibility/handbook/)
