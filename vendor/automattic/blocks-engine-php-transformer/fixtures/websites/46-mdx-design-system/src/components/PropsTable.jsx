/**
 * PropsTable — renders a component's prop API from a declarative array.
 *
 * Usage in MDX:
 *   import { PropsTable } from '@components/PropsTable'
 *   <PropsTable rows={[
 *     { name: 'variant', type: "'solid' | 'soft' | 'ghost'", default: "'solid'", required: false,
 *       description: 'Visual emphasis of the button.' },
 *   ]} />
 */
export function PropsTable({ rows = [] }) {
  return (
    <div className="props-table-wrap" role="region" aria-label="Component props">
      <table className="props-table">
        <thead>
          <tr>
            <th scope="col">Prop</th>
            <th scope="col">Type</th>
            <th scope="col">Default</th>
            <th scope="col">Description</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.name}>
              <td>
                <code className="prop-name">{row.name}</code>
                {row.required && <span className="prop-required" title="Required">*</span>}
              </td>
              <td><code className="prop-type">{row.type}</code></td>
              <td>{row.default ? <code className="prop-default">{row.default}</code> : <span className="prop-empty">—</span>}</td>
              <td>{row.description}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default PropsTable;
