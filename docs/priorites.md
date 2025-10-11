# Priorités produit – SitePulse

## Synthèse
Les travaux en cours visent à transformer SitePulse en une console de monitoring "pro ready". Les modules critiques disposent déjà d’une base robuste (files d’attente normalisées pour l’uptime, journalisation des jobs, exports CSV). Les prochaines itérations doivent capitaliser sur ces briques pour apporter une hiérarchisation claire des actions et une orchestration comparable aux suites Better Uptime ou Datadog.

## Priorités court terme (cycle courant)
1. **Score d’impact transverse** – exploiter les historiques vitesse/uptime/IA pour calculer un indice consolidé par module et l’afficher dans le dashboard et les exports. Objectif : orienter les équipes vers les incidents à fort impact.
2. **Playbooks guidés** – enrichir les checklists existantes (réglages, AI Insights) avec des scénarios prêts à l’emploi incluant temps estimé, rôles impliqués et liens direct vers les actions.
3. **Tableau de synthèse en page d’accueil** – ajouter au-dessus des cartes un bandeau de KPI (SLA global, incidents ouverts, dettes de maintenance) pour matérialiser la priorité du moment.

## Priorités moyen terme (4-8 semaines)
- **File d’attente unifiée Action Scheduler** : généraliser l’utilisation de la file asynchrone (déjà amorcée sur la purge de transients) aux modules IA, vitesse et rapports afin de bénéficier de la priorisation native, de la reprise automatique et des journaux centralisés.
- **Rapports SLA programmables** : automatiser la génération de rapports (CSV/PDF) par agent et par période, en utilisant les historiques déjà persistés dans le module uptime.
- **Visualisations enrichies** : introduire sparklines et jauges sur le dashboard et le bloc Gutenberg pour visualiser immédiatement les écarts vs objectifs.

## Priorités long terme (Q4 et au-delà)
- **Mode collaboratif & intégrations** : synchronisation bidirectionnelle avec Slack/Teams, connecteurs Jira/Linear, historisation des décisions (acquittement, escalade) dans la queue des jobs.
- **Observabilité étendue** : API REST/GraphQL couvrant toutes les métriques (uptime, ressources, erreurs, IA) avec authentification applicative, exports programmés et compatibilité OpenTelemetry.
- **Planification maintenance avancée** : fenêtres de maintenance orchestrées (préparation, execution, post-mortem) avec workflows d’approbation et rapports automatiques.

## Backlog priorisé
| Thème | Objectif | Statut actuel | Prochain incrément |
| --- | --- | --- | --- |
| Score d’impact transverse | Pondérer les alertes par sévérité/recurrence pour afficher une priorité unique | Données disponibles (uptime, vitesse, IA) mais pas d’agrégation | Définir un modèle de scoring + stockage option dédié |
| File d’attente unifiée | Mutualiser l’async pour IA, vitesse, rapports | Queue générique + UI jobs en place, modules encore isolés | Étendre les hooks de planification à Action Scheduler + priorités |
| Dashboard KPI | Donner une vision globale en un coup d’œil | Cartes modulaires et bannière existantes | Ajouter bandeau KPI + CTA contextuels |
| Rapports SLA | Structurer le reporting client | Exports ponctuels seulement | Générer rapports mensuels automatisés |
| Mode collaboratif | Aligner les workflows équipe | Webhooks ponctuels (Slack/Teams pour erreurs) | Introduire connecteurs + suivi des acquittements |

## Écarts par rapport à la roadmap interne
- **Scoring d’impact IA** : absent malgré la disponibilité des historiques et des modules de calcul décrits dans le plan d’amélioration.
- **Profils de seuils Core Web Vitals** : manquent pour aligner la vitesse sur les objectifs multi-device.
- **File asynchrone multi-modules** : seule la purge de transients exploite la queue ; IA, vitesse et maintenance restent synchrones.
- **Rapports SLA et exports programmés** : pas encore implémentés malgré les besoins identifiés pour les clients enterprise.
- **Intégrations API & multi-agents** : la configuration d’agents uptime est fonctionnelle mais sans gestion fine (pondération, suspension) ni exposition REST complète.

## Écarts par rapport à la concurrence
- **Dashboards temps réel** : absence de sparklines/timelines comparables aux consoles Datadog ou Better Uptime.
- **Workflows collaboratifs** : pas d’intégrations Jira/Linear/ServiceNow, là où les suites MSP convertissent chaque alerte en ticket.
- **Rapports brandés automatisés** : pas de PDF/CSV planifiés avec branding client comme Pingdom/Better Stack.
- **Priorisation multi-canal des alertes** : pas d’escalade multi-niveaux ni de pause automatisée après acquittement comme chez Better Stack.
- **Mode sombre/design system avancé** : tokens CSS présents mais manque de composants modernes (gauges, heatmaps) et de bascule claire/sombre pilotée utilisateur.

## Indicateurs de suivi
- **Temps moyen de résolution (MTTR)** : viser < 4 h pour les incidents critiques une fois le scoring déployé.
- **Adoption des checklists guidées** : suivre le % d’utilisateurs qui finalisent les playbooks prioritaires.
- **Taux d’export automatisé** : objectif ≥ 60 % des comptes pro utilisant les rapports SLA programmés.

## Prochaines étapes
1. Prototyper le modèle de score d’impact (pondération par seuil + récence) et valider le stockage.
2. Étendre l’orchestrateur de jobs pour accepter une priorité numérique et la restituer dans l’UI.
3. Concevoir le bandeau KPI (SLA global, incidents actifs, dettes) et définir les sources de données.
4. Lancer une étude concurrentielle approfondie sur les workflows collaboratifs pour orienter les intégrations Q4.
