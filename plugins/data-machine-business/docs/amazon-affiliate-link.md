# Amazon Affiliate Link

`amazon_affiliate_link` searches Amazon products via the Amazon Creators API and returns an affiliate URL, product title, thumbnail URL, and ASIN.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline |
| Mutation risk | Read-only |
| Registered in | Data Machine Business via `DataMachineBusiness\Tools\AmazonAffiliateLink` |
| Access | Admin |
| Config option | `datamachine_amazon_config` |

## Inputs

- `query`: specific product search query.

## Configuration

Configure Credential ID, Credential Secret, Partner Tag, and Marketplace in Data Machine tool settings. Data Machine Business uses the former core option key, `datamachine_amazon_config`, so credentials saved before extraction continue to work after activating this extension.

Use this tool only when a product reference is genuinely useful to the reader.
