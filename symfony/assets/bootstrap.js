import { startStimulusApp } from '@symfony/stimulus-bundle';

// Démarre l'application Stimulus : les contrôleurs de `assets/controllers/` sont
// chargés automatiquement (socle pour le dépliage interactif de J3).
const app = startStimulusApp();

export { app };
