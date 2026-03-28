# Workspace System

> **Important:** The workspace system has been moved to the `data-machine-code` extension plugin as of v0.45.0. This documentation is preserved for reference. Install the extension to use workspace functionality.

The workspace system provides a managed external directory where Data Machine agents can clone, read, write, and perform Git operations on repositories. Unlike agent memory files (which live inside `wp-content/uploads/`), workspace repos live **outside the web root** for security and to support build tooling that shouldn't be publicly accessible.

## Overview

The workspace system consists of:

1. **Workspace service** — core repository management with path containment and Git operations
2. **WorkspaceReader / WorkspaceWriter** — file I/O within workspace repos
3. **WorkspaceAbilities** — WordPress 6.9 Abilities API (16 abilities)
4. **WorkspaceTools / WorkspaceScopedTools** — AI chat tools for global and handler-scoped access
5. **Fetch and Publish handlers** — pipeline integration for reading from and writing to workspace repos
6. **CLI** — full `wp datamachine-code workspace` command set (in extension)

## Workspace Directory

**Default location:** `/var/lib/datamachine/workspace/`

The workspace directory is outside the WordPress web root. It's created on first use and protected with an `index.html` file. Each cloned repository gets its own subdirectory:

```
/var/lib/datamachine/workspace/
  data-machine/          # git clone of Extra-Chill/data-machine
  homeboy/               # git clone of Extra-Chill/homeboy
  chubes-docs/           # git clone of chubes4/chubes-docs
```

## Workspace Service

**Since:** v0.30.0

The `Workspace` class is the core service handling repository management, Git operations, and path security.

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `MAX_READ_SIZE` | 1,048,576 (1 MB) | Maximum file read size |

### Repository Management

| Method | Description |
|--------|-------------|
| `get_path()` | Returns the workspace root path |
| `get_repo_path(string $name)` | Returns the full path for a named repository |
| `ensure_exists()` | Creates the workspace directory if it doesn't exist |
| `list_repos()` | Lists all repositories with Git metadata (remote, branch, last commit) |
| `clone_repo(string $url, ?string $name)` | Clone a Git repository. Name is derived from URL if not provided. |
| `remove_repo(string $name)` | Remove a repository directory (recursive delete) |
| `show_repo(string $name)` | Show repository details (remote, branch, status, last 5 commits) |

### Git Operations

All Git operations validate path containment and execute via `run_git()`, a private method that shells out to the `git` binary within the repo directory.

| Method | Description |
|--------|-------------|
| `git_status(string $name)` | Git status for a repo |
| `git_pull(string $name)` | Pull latest changes |
| `git_add(string $name, string $path)` | Stage files (path relative to repo root, `.` for all) |
| `git_commit(string $name, string $message)` | Commit staged changes |
| `git_push(string $name)` | Push to remote |
| `git_log(string $name, int $limit)` | Recent commits (default: 10) |
| `git_diff(string $name, ?string $path)` | Diff (optionally scoped to a file path) |

### Security

The Workspace class enforces several security measures:

**Path containment:** `validate_containment(string $full_path)` ensures all resolved paths are within the workspace directory. Any path that escapes via traversal (`../`) or symlink resolution is rejected.

**Traversal detection:** `has_traversal(string $path)` checks for `..` components and null bytes in paths.

**Sensitive path protection:** `is_sensitive_path(string $path)` blocks access to files like `.git/config`, `.env`, credentials files, and SSH keys.

**Git mutation guards:** `ensure_git_mutation_allowed(string $name)` checks per-repo policies before allowing write operations (add, commit, push). Repos can be configured as read-only.

**Per-repo policies:** `get_workspace_git_policies()` returns configurable settings:
- `allowed_paths` — restrict file access to specific subdirectories
- `fixed_branch` — lock a repo to a specific branch (prevent checkout of other branches)

## WorkspaceReader


Read-only file operations within workspace repos.

### Methods

**`read_file(string $name, string $path, ?int $offset, ?int $limit)`**

Reads a file from a workspace repo. Supports:
- **Offset/limit** — read a portion of the file (useful for large files)
- **Binary detection** — returns a warning message instead of binary content
- **Size enforcement** — capped at `Workspace::MAX_READ_SIZE` (1 MB)

Returns `{success, content, file, size, offset?, limit?, truncated?}`.

**`list_directory(string $name, string $path)`**

Lists directory contents, sorted with directories first (suffixed with `/`), then files. Filters out `.` and `..` entries.

Returns `{success, repo, path, entries[]}`.

## WorkspaceWriter


Write and edit operations within workspace repos.

### Methods

**`write_file(string $name, string $path, string $content)`**

Creates or overwrites a file. Creates intermediate directories as needed. Performs post-write containment verification to ensure the written file is still within the workspace.

Returns `{success, file, size, message}`.

**`edit_file(string $name, string $path, string $old_string, string $new_string)`**

Find-and-replace within a file. Reads the file, counts occurrences of `old_string`, replaces all occurrences, and writes back. Returns an error if:
- The file doesn't exist
- `old_string` is not found in the file

Returns `{success, file, occurrences, message}`.

## Abilities


Sixteen abilities registered under the `datamachine` category, split into read-only and mutating operations:

### Read-Only Abilities

| Ability | Description |
|---------|-------------|
| `datamachine/workspace-path` | Get the workspace root directory path |
| `datamachine/workspace-list` | List all workspace repositories with Git metadata |
| `datamachine/workspace-show` | Show detailed repository info (remote, branch, status, recent commits) |
| `datamachine/workspace-read` | Read a file from a workspace repo (with offset/limit) |
| `datamachine/workspace-ls` | List directory contents within a repo |
| `datamachine/workspace-git-status` | Git status for a repo |
| `datamachine/workspace-git-log` | Recent Git commits (configurable limit) |
| `datamachine/workspace-git-diff` | Git diff (optionally scoped to a file) |

### Mutating Abilities

| Ability | Description |
|---------|-------------|
| `datamachine/workspace-clone` | Clone a Git repository into the workspace |
| `datamachine/workspace-remove` | Remove a workspace repository |
| `datamachine/workspace-write` | Write/create a file in a workspace repo |
| `datamachine/workspace-edit` | Find-and-replace within a file |
| `datamachine/workspace-git-pull` | Pull latest changes |
| `datamachine/workspace-git-add` | Stage files for commit |
| `datamachine/workspace-git-commit` | Commit staged changes |
| `datamachine/workspace-git-push` | Push commits to remote |

Read-only abilities have `show_in_rest: true`. Mutating abilities have `show_in_rest: false` for safety.

## AI Tools

### Global Tools (WorkspaceTools)

> **Note:** WorkspaceTools have been moved to the `data-machine-code` extension plugin.

**Tool ID:** Various (`workspace_path`, `workspace_list`, `workspace_show`, `workspace_ls`, `workspace_read`)
**Contexts:** `chat`, `pipeline`, `standalone`

Five read-only tools available globally to AI agents:

| Tool | Description |
|------|-------------|
| `workspace_path` | Get workspace root directory path |
| `workspace_list` | List all workspace repositories |
| `workspace_show` | Show repository details (remote, branch, status) |
| `workspace_ls` | List directory contents in a repo |
| `workspace_read` | Read a file from a repo (with offset/limit) |

These tools are available whenever the workspace is configured (`is_configured()` checks that the workspace directory exists and is readable).

### Scoped Tools (WorkspaceScopedTools)


Handler-scoped tools registered by the Workspace fetch and publish handlers. These tools enforce per-handler path allowlists — operations are restricted to the paths configured in the handler settings.

**8 operations dispatched via `handle_tool_call()`:**

| Operation | Registered By | Description |
|-----------|---------------|-------------|
| `fetch_ls` | Fetch handler | List directory (scoped to handler paths) |
| `fetch_read` | Fetch handler | Read file (scoped to handler paths) |
| `publish_write` | Publish handler | Write file (scoped to writable paths) |
| `publish_edit` | Publish handler | Edit file (scoped to writable paths) |
| `git_pull` | Publish handler | Pull latest changes |
| `git_add` | Publish handler | Stage files (conditional) |
| `git_commit` | Publish handler | Commit changes (conditional on `commit_enabled`) |
| `git_push` | Publish handler | Push to remote (conditional on `push_enabled`) |

All operations validate paths against the handler's configured allowlist before delegating to the Abilities API.

## Pipeline Integration

### Fetch Handler


Reads data from workspace repositories as a pipeline fetch source. Configured with:

| Setting | Description |
|---------|-------------|
| `repo` | Repository name in the workspace |
| `paths` | Array of file/directory paths to fetch |
| `max_files` | Maximum number of files to process |
| `since_commit` | Only fetch files changed since this commit hash |
| `include_glob` | File pattern inclusion filter |
| `exclude_glob` | File pattern exclusion filter |

The fetch handler produces structured JSON data packets and registers scoped `workspace_fetch_ls` and `workspace_fetch_read` tools for the AI step.

### Publish Handler


Writes data to workspace repositories as a pipeline publish target. Configured with:

| Setting | Description |
|---------|-------------|
| `repo` | Repository name in the workspace |
| `writable_paths` | Array of paths the AI can write to |
| `branch_mode` | Branch strategy (`current` or `fixed`) |
| `fixed_branch` | Branch name when using `fixed` mode |
| `commit_enabled` | Whether the AI can commit changes |
| `push_enabled` | Whether the AI can push to remote |
| `commit_message` | Default commit message template |

The publish handler registers scoped tools for writing, editing, and Git operations. Git tools (`git_add`, `git_commit`, `git_push`) are registered conditionally based on the handler settings.

**Note:** The publish handler's `executePublish()` returns a noop success — actual publishing is driven by the AI calling the scoped tools during the AI step.

## CLI


### Repository Management

```bash
# Show workspace path
wp datamachine workspace path

# List all repositories
wp datamachine workspace list [--format=table|json]

# Clone a repository
wp datamachine workspace clone <url> [<name>]

# Remove a repository
wp datamachine workspace remove <name>

# Show repository details
wp datamachine workspace show <name>
```

### File Operations

```bash
# Read a file
wp datamachine workspace read <name> <path> [--offset=<n>] [--limit=<n>]

# List directory contents
wp datamachine workspace ls <name> [<path>]

# Write a file (supports @/path/to/local/file syntax)
wp datamachine workspace write <name> <path> --content=<content>

# Edit a file (find-and-replace)
wp datamachine workspace edit <name> <path> --old=<old_string> --new=<new_string>
```

### Git Operations

```bash
# Git status
wp datamachine workspace git <name> status

# Pull latest
wp datamachine workspace git <name> pull

# Stage files
wp datamachine workspace git <name> add [<path>]

# Commit
wp datamachine workspace git <name> commit --message=<message>

# Push
wp datamachine workspace git <name> push

# View log
wp datamachine workspace git <name> log [--limit=<n>]

# View diff
wp datamachine workspace git <name> diff [<path>]
```

### Special Syntax

The `--content`, `--old`, and `--new` flags support `@/path/to/file` syntax to read content from a local file instead of passing it inline. This is useful for writing multi-line content or avoiding shell escaping issues:

```bash
wp datamachine workspace write my-repo docs/README.md --content=@/tmp/readme-content.md
```

## Architecture Diagram

```
            AI Chat Tools              Pipeline Steps              CLI
                 |                          |                       |
    +------------+-------+       +---------+--------+              |
    |                    |       |                   |              |
    v                    v       v                   v              v
WorkspaceTools    WorkspaceScopedTools    Fetch/Publish      WorkspaceCommand
  (global,          (handler-scoped,      Handlers              |
   read-only)        path-restricted)        |                  |
    |                    |                   |                  |
    +--------+-----------+-------------------+------------------+
             |
             v
      WorkspaceAbilities
    (16 WordPress Abilities)
             |
      +------+------+
      |             |
      v             v
  Workspace    WorkspaceReader
  (core)       WorkspaceWriter
      |
      v
  /var/lib/datamachine/workspace/
    repo-1/  repo-2/  repo-3/
```

## Source Files

| File | Purpose |
|------|---------|
| `inc/Core/FilesRepository/Workspace.php` | Core service — repo management, Git operations, path security |
| `inc/Core/FilesRepository/WorkspaceReader.php` | File reading with offset/limit and binary detection |
| `inc/Core/FilesRepository/WorkspaceWriter.php` | File writing and find-and-replace editing |
| `inc/Abilities/WorkspaceAbilities.php` | WordPress 6.9 Abilities (16 abilities) |
| `inc/Engine/AI/Tools/Global/WorkspaceTools.php` | Global AI tools (5 read-only tools) |
| `inc/Core/Steps/Workspace/Tools/WorkspaceScopedTools.php` | Handler-scoped AI tools (8 operations) |
| `inc/Core/Steps/Fetch/Handlers/Workspace/Workspace.php` | Pipeline fetch handler |
| `inc/Core/Steps/Fetch/Handlers/Workspace/WorkspaceSettings.php` | Fetch handler settings |
| `inc/Core/Steps/Publish/Handlers/Workspace/Workspace.php` | Pipeline publish handler |
| `inc/Core/Steps/Publish/Handlers/Workspace/WorkspaceSettings.php` | Publish handler settings |
| `inc/Cli/Commands/WorkspaceCommand.php` | WP-CLI commands |
