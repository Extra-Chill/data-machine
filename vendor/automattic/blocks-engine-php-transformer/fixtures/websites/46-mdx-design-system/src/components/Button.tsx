/**
 * Halo UI — Button
 * @halo-ui/react · packages/react/src/Button/Button.tsx
 *
 * A pressable control. The default export consumed by docs CodePreviews.
 * Tokens come from @halo-ui/tokens (CSS custom properties), so the component
 * stays themeable without recompiling.
 */
import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import clsx from 'clsx';

export type ButtonVariant = 'solid' | 'soft' | 'outline' | 'ghost';
export type ButtonTone = 'brand' | 'neutral' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  tone?: ButtonTone;
  size?: ButtonSize;
  /** Disables interaction and shows a spinner. */
  loading?: boolean;
  /** Icon rendered before the label. */
  iconStart?: ReactNode;
  /** Icon rendered after the label. */
  iconEnd?: ReactNode;
  /** Stretch to fill the inline container. */
  fullWidth?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'solid',
    tone = 'brand',
    size = 'md',
    loading = false,
    iconStart,
    iconEnd,
    fullWidth = false,
    disabled,
    className,
    children,
    ...rest
  },
  ref,
) {
  return (
    <button
      ref={ref}
      type="button"
      data-loading={loading || undefined}
      aria-busy={loading || undefined}
      disabled={disabled || loading}
      className={clsx(
        'halo-button',
        `halo-button--${variant}`,
        `halo-button--${tone}`,
        `halo-button--${size}`,
        fullWidth && 'halo-button--block',
        className,
      )}
      {...rest}
    >
      {loading && <span className="halo-button__spinner" aria-hidden="true" />}
      {!loading && iconStart && <span className="halo-button__icon">{iconStart}</span>}
      <span className="halo-button__label">{children}</span>
      {!loading && iconEnd && <span className="halo-button__icon">{iconEnd}</span>}
    </button>
  );
});

export default Button;
