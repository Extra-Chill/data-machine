/**
 * Swatch & SwatchGrid — color chips for the Foundations → Color page.
 *
 * Usage in MDX:
 *   import { Swatch, SwatchGrid } from '@components/Swatch'
 *   <SwatchGrid>
 *     <Swatch name="indigo/600" token="--halo-color-indigo-600" color="#4f46e5" />
 *   </SwatchGrid>
 */
function contrastText(hex) {
  const c = hex.replace('#', '');
  const r = parseInt(c.slice(0, 2), 16);
  const g = parseInt(c.slice(2, 4), 16);
  const b = parseInt(c.slice(4, 6), 16);
  // Relative luminance (sRGB approximation) → pick readable label color.
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.55 ? '#0b0d17' : '#f4f5fb';
}

export function Swatch({ name, token, color }) {
  return (
    <figure className="swatch">
      <div
        className="swatch__chip"
        style={{ background: color, color: contrastText(color) }}
      >
        <span className="swatch__hex">{color.toUpperCase()}</span>
      </div>
      <figcaption className="swatch__meta">
        <span className="swatch__name">{name}</span>
        <code className="swatch__token">{token}</code>
      </figcaption>
    </figure>
  );
}

export function SwatchGrid({ children }) {
  return <div className="swatch-grid">{children}</div>;
}

export default Swatch;
