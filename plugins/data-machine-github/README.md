# Data Machine GitHub

Fetch files from public or private GitHub repositories into [Data Machine](../data-machine) pipelines. The **GitHub Files** fetch handler reports additions, modifications, and removals so downstream steps can publish, update, or unpublish content.

Activate Data Machine and Data Machine GitHub, then add a **GitHub Files** fetch step and set `repo` to `owner/name`. Public repositories work without credentials. For private repositories, save a GitHub personal access token under **Data Machine → Settings → Auth Providers**.

See [GitHub Files fetch handler](docs/github-files.md) for configuration and the packet contract.
