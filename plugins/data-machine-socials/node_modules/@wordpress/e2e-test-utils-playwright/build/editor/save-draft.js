"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.saveDraft = saveDraft;
/**
 * Saves the post as a draft, resolving once the request is complete (once a notice
 * is displayed).
 */
async function saveDraft() {
    await this.page
        .getByRole('region', { name: 'Editor top bar' })
        .getByRole('button', { name: 'Save draft' })
        .click();
    await this.page
        .getByRole('button', { name: 'Dismiss this notice' })
        .filter({ hasText: 'Draft saved' })
        .waitFor();
}
//# sourceMappingURL=save-draft.js.map