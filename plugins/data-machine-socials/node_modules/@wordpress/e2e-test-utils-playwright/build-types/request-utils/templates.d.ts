/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
type TemplateType = 'wp_template' | 'wp_template_part';
interface Template {
    wp_id: number;
    id: string;
}
interface CreateTemplatePayload {
    slug: string;
    title?: string;
    content?: string;
    description?: string;
}
/**
 * Delete all the templates of given type.
 *
 * @param this
 * @param type - Template type to delete.
 */
declare function deleteAllTemplates(this: RequestUtils, type: TemplateType): Promise<void>;
/**
 * Creates a new template using the REST API.
 *
 * @param this
 * @param type    Template type to delete.
 * @param payload Template attributes.
 */
declare function createTemplate(this: RequestUtils, type: TemplateType, payload: CreateTemplatePayload): Promise<Template>;
export { deleteAllTemplates, createTemplate };
//# sourceMappingURL=templates.d.ts.map