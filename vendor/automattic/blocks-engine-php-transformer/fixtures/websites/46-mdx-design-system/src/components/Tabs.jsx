/**
 * Tabs / Tab — framework-switcher tabs used in install snippets and examples.
 *
 * Usage in MDX:
 *   import { Tabs, Tab } from '@components/Tabs'
 *   <Tabs labels={['React', 'Vue', 'CSS']}>
 *     <Tab>...</Tab>
 *     <Tab>...</Tab>
 *     <Tab>...</Tab>
 *   </Tabs>
 */
import { useState, Children } from 'react';

export function Tabs({ labels = [], children }) {
  const [active, setActive] = useState(0);
  const panels = Children.toArray(children);

  return (
    <div className="tabs">
      <div className="tabs__list" role="tablist">
        {labels.map((label, i) => (
          <button
            key={label}
            role="tab"
            id={`tab-${i}`}
            aria-selected={active === i}
            aria-controls={`panel-${i}`}
            tabIndex={active === i ? 0 : -1}
            className={`tabs__tab${active === i ? ' is-active' : ''}`}
            onClick={() => setActive(i)}
            type="button"
          >
            {label}
          </button>
        ))}
      </div>
      {panels.map((panel, i) => (
        <div
          key={i}
          role="tabpanel"
          id={`panel-${i}`}
          aria-labelledby={`tab-${i}`}
          hidden={active !== i}
          className="tabs__panel"
        >
          {panel}
        </div>
      ))}
    </div>
  );
}

export function Tab({ children }) {
  return <>{children}</>;
}

export default Tabs;
