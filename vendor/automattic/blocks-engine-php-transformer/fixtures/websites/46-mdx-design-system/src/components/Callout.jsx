/**
 * Callout — an emphasized aside used throughout the Halo UI docs.
 *
 * Usage in MDX:
 *   import { Callout } from '@components/Callout'
 *   <Callout type="warning" title="Heads up">
 *     Don't nest interactive controls inside a Button.
 *   </Callout>
 */
const ICONS = {
  info: 'M8 7v4M8 5.5v.5',
  success: 'M5 8.5l2 2 4-4.5',
  warning: 'M8 6v3.2M8 11v.4',
  danger: 'M6 6l4 4M10 6l-4 4',
};

const LABELS = {
  info: 'Note',
  success: 'Tip',
  warning: 'Caution',
  danger: 'Warning',
};

export function Callout({ type = 'info', title, children }) {
  const label = title ?? LABELS[type] ?? 'Note';
  return (
    <aside className={`callout callout--${type}`} role="note">
      <span className="callout__icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8">
          <circle cx="8" cy="8" r="6" />
          <path d={ICONS[type]} strokeLinecap="round" />
        </svg>
      </span>
      <div className="callout__body">
        <p className="callout__title">{label}</p>
        <div className="callout__content">{children}</div>
      </div>
    </aside>
  );
}

export default Callout;
