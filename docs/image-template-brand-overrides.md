# Per-render image branding

`datamachine/render-image-template` accepts an optional `brand` object alongside
`template_id`, `data`, `preset`, and `format`:

```json
{
  "template_id": "poster",
  "data": { "title": "Community gathering", "background": "none" },
  "brand": {
    "colors": { "background_dark": "#123456", "accent": "#f80" },
    "fonts": { "heading": "/srv/assets/heading.ttf" },
    "logo_path": 123,
    "brand_text": "Community"
  }
}
```

CLI callers pass the same object with `--brand='<json>'`:

```sh
wp datamachine image render --template_id=poster --data='{"title":"Community gathering"}' --brand='{"colors":{"accent":"#f80"}}'
```

Overrides apply after the site-wide `datamachine/image_template/brand_tokens`
filter. Omitted or invalid entries retain the resolved site token. Colors accept
`#RGB` or `#RRGGBB`, using the existing color token keys. Font roles are `heading`,
`display`, `body`, `brand`, and `mono`. Fonts, `logo_path`, and
`logo_path_inverse` accept readable local files or media-library attachment IDs
(including numeric strings); no remote assets are downloaded. Fonts must be in a
GD-compatible format such as TTF/OTF. `brand_text` accepts a string.

The render scope covers all BrandTokens accessors, including templates registered
by extensions, and is restored even if rendering throws. Direct PHP consumers can
also pass overrides as the third argument to `BrandTokens::get()`.
