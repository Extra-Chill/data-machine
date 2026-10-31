# Theming Layer Guide

Data Machine has two theming surfaces because it renders in two different runtimes:

| Surface | Runtime | Consumer |
| --- | --- | --- |
| CSS custom properties | Browser | Admin UI, frontend widgets, block UIs |
| `BrandTokens` PHP filter | Server-side PHP/GD | Image templates rendered by `datamachine/render-image-template` |

Use CSS custom properties when the browser paints the UI. Use `BrandTokens` when PHP renders pixels before the browser sees the output.

## Pick A Surface

| I am building... | Use | Why |
| --- | --- | --- |
| wp-admin screens | CSS custom properties | Browser CSS can read `--datamachine-*` tokens directly. |
| frontend chat or widgets | CSS custom properties | Site themes can override or bridge browser tokens. |
| block-editor or block-rendered UI | CSS custom properties | Blocks render in a browser CSS environment. |
| OG cards or generated images | `BrandTokens` | GD cannot read CSS variables while rendering server-side. |
| email-ready generated images | `BrandTokens` | The image is rasterized before browser CSS exists. |

Do not use a PHP filter to style browser UI. Do not use CSS variables as the only source for GD image templates.

## CSS Tokens

Browser-rendered interfaces read tokens from `inc/Core/Admin/assets/css/root.css`.

Core tokens:

| Token | Purpose |
| --- | --- |
| `--datamachine-text-primary` | Primary text |
| `--datamachine-text-secondary` | Secondary text |
| `--datamachine-text-muted` | Muted text |
| `--datamachine-border-1` | Primary border |
| `--datamachine-border-2` | Secondary border |
| `--datamachine-border-dashed` | Standard dashed border |
| `--datamachine-border-dotted` | Standard dotted border |
| `--datamachine-bg-light` | Light background |
| `--datamachine-bg-lighter` | Lighter background |
| `--datamachine-blue` | Core blue accent |
| `--datamachine-color-success` | Success state |
| `--datamachine-color-error` | Error state |
| `--datamachine-color-warning` | Warning state |
| `--datamachine-color-neutral` | Neutral state |
| `--datamachine-spacing-xs` | 8px spacing |
| `--datamachine-spacing-sm` | 12px spacing |
| `--datamachine-spacing-md` | 16px spacing |
| `--datamachine-spacing-lg` | 24px spacing |
| `--datamachine-spacing-xl` | 40px spacing |
| `--datamachine-border-radius` | Shared radius |

Themes and plugins can bridge site tokens into Data Machine tokens:

```css
:root {
    --datamachine-text-primary: var(--wp--preset--color--foreground);
    --datamachine-text-secondary: var(--wp--preset--color--contrast-2);
    --datamachine-bg-light: var(--wp--preset--color--base-2);
    --datamachine-blue: var(--wp--preset--color--primary);
    --datamachine-border-radius: var(--wp--custom--radius--small);
}
```

Consumers should read the Data Machine token names, not site-specific names. That keeps Data Machine UI portable across themes.

## BrandTokens

Server-side image templates read `DataMachine\Abilities\Media\BrandTokens`.

GD cannot read CSS variables, web fonts, or browser-computed styles. Templates call `BrandTokens::get()`, `BrandTokens::color()`, or `BrandTokens::font()` and receive plain PHP values.

Core token shape:

| Key | Purpose |
| --- | --- |
| `colors.background` | Default light background |
| `colors.background_dark` | Default dark background |
| `colors.surface` | Card or panel surface |
| `colors.accent` | Primary accent |
| `colors.accent_hover` | Accent hover/variant |
| `colors.accent_2` | Secondary accent |
| `colors.accent_3` | Tertiary accent |
| `colors.text_primary` | Primary text |
| `colors.text_muted` | Muted text |
| `colors.text_inverse` | Text over dark/accent backgrounds |
| `colors.header_bg` | Header background |
| `colors.border` | Borders and separators |
| `fonts.heading` | Absolute path to heading TTF/OTF |
| `fonts.body` | Absolute path to body TTF/OTF |
| `fonts.brand` | Absolute path to brand TTF/OTF |
| `fonts.mono` | Absolute path to monospace TTF/OTF |
| `logo_path` | Absolute path to logo image |
| `brand_text` | Brand name text |
| `site_label` | Site or network label |

Example bridge:

```php
use DataMachine\Abilities\Media\BrandTokens;

add_filter(
    'datamachine/image_template/brand_tokens',
    static function ( array $tokens, string $template_id, $context ): array {
        $tokens['colors']['background']   = '#ffffff';
        $tokens['colors']['accent']       = '#0073aa';
        $tokens['colors']['text_primary'] = '#111111';
        $tokens['brand_text']             = get_bloginfo( 'name' );
        $tokens['logo_path']              = get_stylesheet_directory() . '/assets/logo.png';
        $tokens['fonts']['heading']       = get_stylesheet_directory() . '/assets/fonts/Heading.ttf';

        return $tokens;
    },
    10,
    3
);

$accent = BrandTokens::color( 'accent', 'event_og_card' );
```

Font paths must point to TTF or OTF files. GD cannot render WOFF2 directly.

## Alignment Rules

Data Machine keeps the two surfaces logically aligned, not mechanically identical.

- New generic browser tokens should have an equivalent `BrandTokens` value when server-rendered images need the same concept.
- New `BrandTokens` values should have an equivalent CSS custom property when browser-rendered UI needs the same concept.
- Consumer plugins should not redefine a generic token that Data Machine already publishes.
- Site-specific values should bridge into Data Machine's token names instead of replacing the token vocabulary.

If a third theming surface appears, consider moving the shared logical token catalog to a generated source. Until then, parallel explicit surfaces are simpler than build-time indirection.
