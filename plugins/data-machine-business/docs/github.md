# GitHub Files fetch handler

The `github` fetch handler reads the `files` data source from GitHub.com. It resolves the branch tip, reads its recursive Git tree, and downloads matching blobs by SHA so every file in a fetch comes from the same commit.

## Configuration

| Field | Default | Meaning |
| --- | --- | --- |
| `repo` | Required | GitHub repository in `owner/name` format, not a URL. |
| `branch` | Repository default | Use the exact branch name, such as `release/docs`. The default branch name is cached for five minutes. |
| `file_path` | `''` | Fetch from the whole repository, a directory and its descendants, or an exact file. `docs` does not match `docs-extra`. Leading and trailing slashes are ignored. |
| `file_pattern` | `''` | No glob filter. A glob without a slash, such as `*.md`, matches filenames at any depth. With a slash, it matches the path relative to `file_path`, and `*` does not cross a slash. Matching is case-sensitive. |
| `max_file_size` | `1048576` | Maximum file size in bytes (1–104857600). Larger files are skipped before download. |

For public repositories no credential is required, even though Data Machine may show a **Requires Auth** badge to expose the provider settings. For private repositories, save a GitHub fine-grained personal access token with **Contents: Read-only** access to the selected repository in **Data Machine → Settings → Auth Providers**. The `github` provider stores the PAT as the encrypted `access_token` credential and sends it as a bearer token. Do not put the token in step settings. There is no OAuth flow.

## Item contract

The packet's `data.title` is the repository-relative file path (prefixed with `Removed:` for removals), and `data.body` is the decoded file content. A removal has an empty body; use `metadata.github_change` to distinguish it from an empty file.

| Metadata | Meaning |
| --- | --- |
| `source_type` | `github` |
| `github_repo`, `github_branch` | Repository and resolved branch. |
| `github_commit_sha` | Commit examined by the fetch; for removals, the first commit seen without the file. |
| `github_file_path` | Full repository-relative path, e.g. `docs/setup/install.md`. |
| `github_relative_file_path` | Path relative to `file_path`, e.g. `setup/install.md` when `file_path` is `docs`. With an exact-file prefix this is the filename. |
| `github_file_sha` | Git blob SHA; for a removal, the last tracked blob SHA. |
| `github_url`, `source_url` | The same commit-pinned file URL. For a removal, the URL points at the last tracked revision where the file existed. |
| `github_change` | `added` when the file first enters the snapshot, `modified` when its blob SHA changes, or `removed` when a tracked file disappears. |
| `dedup_key`, `original_id` | `github_<repo>_files_<path>_<blobsha>`, with the full repository-relative path and the blob SHA. |
| `item_identifier` | Internal key for Data Machine's processed-items lifecycle. Removals have a separate identity so a processed file does not suppress its removal. |

For DocSync, route `removed` items to unpublish and use the repository and full file path to identify the destination. Use the file SHA or `dedup_key` to identify a revision, not as the stable destination key.

## Change tracking and failures

A flow step stores its snapshot in Data Machine's Tracked Items ledger. There are no removals on the first run. A successful fetch updates the snapshot; if a GitHub request fails or the tree is truncated, the prior snapshot is retained. Snapshots are separate for each flow step, repository, branch, prefix, and glob. An oversized file still present in the tree does not count as removed.

The handler checks Data Machine's processed-items ledger before each batch of blob downloads. Once downstream processing completes, a repeat fetch of the same file path and blob SHA skips that revision, even if it reappears later. With an explicit or cached branch, an unchanged, fully processed fetch only requests the commit and tree. Unprocessed removals are retried while the path remains absent. Direct execution has no durable snapshot or deduplication.

An existing path with no matching files can succeed with no items. A configured prefix that no longer exists fails with `github_missing_file_path`, rather than treating the entire source as removed. For example, to detect deletion of a directory and all its files, use a surviving ancestor (or the repository root) as `file_path`. GitHub API failures and truncated trees also fail the fetch step.
