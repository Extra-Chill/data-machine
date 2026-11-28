# Stack image template

Use `datamachine/render-image-template` with `template_id: "stack"`, or:

```sh
wp datamachine image render --template_id=stack --preset=print_pocket_flyer --data='{"background":"#182028","blocks":[{"type":"text","text":"Get involved","font":"heading","size":80,"fit":true},{"type":"qr","url":"https://example.org/join","size":0.45,"gap_before":24},{"type":"list","items":["Scan the code","Choose a project","Invite a friend"],"style":"numbered","size":36,"gap_before":24}]}'
```

All platform presets work. Pixel dimensions scale from a 1080px-wide base.
Top-level fields are `background` (hex or brand color token, default
`background_dark`), `padding` (64), `align` (`center` or `left`), and ordered
`blocks`. Colors accept hex or brand color keys; fonts accept `heading`, `body`,
`display`, or `brand`.

Every block accepts `gap_before` in base pixels:

| Type | Fields |
| --- | --- |
| `text` | `text`, `font`, `color`, `size` (32), `fit`, `max_size`, `wrap` (true), `line_height` (1.2), `align` |
| `image` | `path` or `attachment_id`, `width` (fraction <=1 or base pixels), `max_height` |
| `qr` | `url`, `size` (content-width fraction, default .5), `card_color` (white), `quiet_modules` (1–4) |
| `row` | `items` (text/image blocks), `gap` (16 base pixels); one line, vertically centered |
| `list` | string `items`, `style` (`numbered`, `bullets`, `plain`), `marker_color`, `font`, `color`, `size`, `line_height` |
| `divider` | `width` (fraction or base pixels), `thickness` (2), `color` |
| `spacer` | `height`, `flex` |

`fit` text shrinks to fit its width; with wrapping enabled it fits the longest
word, and with `wrap: false` it fits the entire line. `max_size` optionally
sets a larger starting size. If the stack is too tall, fit-enabled text and
lists shrink proportionally, down to 1px, before reporting overflow. Fixed
images, QR codes, spacers and non-fitting text retain their dimensions. No
blocks are removed to resolve overflow.

Flex spacers share spare height; without flex spacers, spare height is spread
between blocks. QR quiet-zone cards are included in layout bounds and use
nearest-neighbor scaling. A QR provider must answer the existing
`datamachine/image_generation/qr_image_path` filter; it receives the final QR
pixel size and returns a temporary image that the template deletes.

Ability results include `warnings` for unresolved overflow, unavailable assets
and unsupported blocks. `dropped_fields` identifies skipped asset/block paths;
height overflow itself does not mark blocks as dropped. The CLI prints warnings.
