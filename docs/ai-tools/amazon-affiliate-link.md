# Amazon Affiliate Link

`amazon_affiliate_link` searches Amazon products and returns an affiliate link, product title, and thumbnail.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `AmazonAffiliateLink` |
| Access | Admin |

## Inputs

- `query`: specific product search query.

Use this only when a product reference is genuinely useful to the reader.
