import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur Stimulus de socle (J1) : présence minimale prouvant le câblage
 * AssetMapper + Stimulus. Le dépliage interactif réel arrive en J3.
 */
export default class extends Controller {
    connect() {
        this.element.dataset.helloConnected = 'true';
    }
}
