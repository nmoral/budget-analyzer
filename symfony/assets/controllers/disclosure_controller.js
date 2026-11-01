import { Controller } from '@hotwired/stimulus';

/*
 * Dépliage d'un terme (#20, C3) : montre/masque le bloc détail (formule, entrées
 * datées, sources). Le contenu est rendu server-side et déjà présent dans le HTML
 * (lisible sans JS) ; ce contrôleur ne fait que basculer sa visibilité.
 */
export default class extends Controller {
    static targets = ['panel', 'trigger'];

    toggle() {
        const isHidden = this.panelTarget.hasAttribute('hidden');

        if (isHidden) {
            this.panelTarget.removeAttribute('hidden');
        } else {
            this.panelTarget.setAttribute('hidden', '');
        }

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        }
    }
}
