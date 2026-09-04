/**
 * DoDont / Do / Dont — paired guidance cards for usage guidelines.
 *
 * Usage in MDX:
 *   import { DoDont, Do, Dont } from '@components/DoDont'
 *   <DoDont>
 *     <Do>Use one primary button per view.</Do>
 *     <Dont>Stack three solid buttons of equal weight.</Dont>
 *   </DoDont>
 */
export function DoDont({ children }) {
  return <div className="dodont">{children}</div>;
}

export function Do({ children }) {
  return (
    <div className="dodont__card dodont__card--do">
      <span className="dodont__label">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
          <path d="M3.5 8.5l3 3 6-7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        Do
      </span>
      <p>{children}</p>
    </div>
  );
}

export function Dont({ children }) {
  return (
    <div className="dodont__card dodont__card--dont">
      <span className="dodont__label">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
          <path d="M4 4l8 8M12 4l-8 8" strokeLinecap="round" />
        </svg>
        Don't
      </span>
      <p>{children}</p>
    </div>
  );
}

export default DoDont;
