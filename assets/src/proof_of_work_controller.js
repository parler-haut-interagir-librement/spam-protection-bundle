/*
 * The Stimulus controller of phil/spam-protection-bundle's proof of work, on the hidden proof field:
 *
 *     <input type="hidden" data-controller="phil--spam-protection-bundle--proof-of-work"
 *            data-phil--spam-protection-bundle--proof-of-work-challenge-value="…"
 *            data-phil--spam-protection-bundle--proof-of-work-difficulty-value="12">
 *
 * When the field connects — the page loads, or a form is inserted later, in a modal or a Turbo frame —, it solves the
 * challenge and writes the solution into the field. A form submitted before it ends is held for spam, as a robot's
 * would be.
 */
import { Controller } from '@hotwired/stimulus';
import { solve } from './proof_of_work.js';

export default class extends Controller {
    static values = { challenge: String, difficulty: Number };

    async connect() {
        this.element.value = await solve(this.challengeValue, this.difficultyValue);
    }
}
