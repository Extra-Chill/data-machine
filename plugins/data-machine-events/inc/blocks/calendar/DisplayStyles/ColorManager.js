/**
 * ColorManager
 * Centralized helper for working with color CSS custom properties.
 * Exposes functions that return `var(...)` references and optional computed values.
 */
export const ColorManager = {
    /**
     * Return a CSS var reference for fill (rgba) for a given dayName.
     * Includes a fallback to a subtle neutral rgba.
     * Example: "var(--datamachine-day-monday-rgba, rgba(0,0,0,0.03))"
     */
    getFillVar(dayName) {
        if (!dayName) return `rgba(0,0,0,0.03)`;
        return `var(--datamachine-day-${dayName}-rgba, rgba(0,0,0,0.03))`;
    },

    /**
     * Return a CSS var reference for stroke (solid color) for a given dayName.
     * Includes fallback to --datamachine-border-default.
     * Example: "var(--datamachine-day-monday, var(--datamachine-border-default))"
     */
    getStrokeVar(dayName) {
        if (!dayName) return `var(--datamachine-border-default)`;
        return `var(--datamachine-day-${dayName}, var(--datamachine-border-default))`;
    },

    /**
     * Resolve a CSS custom property value from :root (computed value).
     * Returns trimmed string or empty string if not present.
     */
    getComputedVar(varName) {
        if (!varName) return '';
        try {
            const styles = getComputedStyle(document.documentElement);
            return styles.getPropertyValue(varName).trim() || '';
        } catch (e) {
            return '';
        }
    },

    /**
     * Apply fill and stroke to a given element using var(...) references by default.
     * Options:
     *  - useVar (boolean): when true (default) set var(...) references; when false, resolve computed values and set concrete colors.
     *  - fillFallback / strokeFallback: explicit fallbacks if desired.
     */
    applyToElement(element, dayName, opts = {}) {
        if (!element) return;
        const { useVar = true, fillFallback = null, strokeFallback = null } = opts;
        const fillVar = this.getFillVar(dayName);
        const strokeVar = this.getStrokeVar(dayName);

        const isSVG = element instanceof SVGElement;

        if (useVar) {
            if (isSVG) {
                element.setAttribute('fill', fillVar);
                element.setAttribute('stroke', strokeVar);
            } else {
                element.style.setProperty('fill', fillVar);
                element.style.setProperty('stroke', strokeVar);
            }
        } else {
            const fillValue = fillFallback || this.getComputedVar(`--datamachine-day-${dayName}-rgba`) || 'rgba(0,0,0,0.03)';
            const strokeValue = strokeFallback || this.getComputedVar(`--datamachine-day-${dayName}`) || this.getComputedVar('--datamachine-border-default') || '#d1d5db';
            if (isSVG) {
                element.setAttribute('fill', fillValue);
                element.setAttribute('stroke', strokeValue);
            } else {
                element.style.setProperty('fill', fillValue);
                element.style.setProperty('stroke', strokeValue);
            }
        }
    }
};
