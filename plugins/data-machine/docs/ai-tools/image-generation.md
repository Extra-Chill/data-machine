# Image Generation

`image_generation` creates an image-generation job through `wp-ai-client`, then sideloads the result as WordPress media.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline |
| Mutation risk | Low mutation |
| Registered in | `ToolServiceProvider.php` via `ImageGeneration` |
| Backing ability | `datamachine/generate-image` |

## Inputs

- `prompt`: detailed image prompt.
- `provider`: optional `wp-ai-client` provider override.
- `model`: optional image model override.
- `aspect_ratio`: `1:1`, `3:4`, `4:3`, `9:16`, or `16:9`.

Default aspect ratio is `3:4` for portrait blog/Pinterest images.
