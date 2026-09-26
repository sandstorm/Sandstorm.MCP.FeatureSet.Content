# Sandstorm.MCP.FeatureSet.Content

MCP tools for Neos 9 content repository write operations that the upstream CR feature set
([sjs/neos-mcp-feature-set-cr](https://github.com/sjsone/SJS.Neos.MCP.FeatureSet.CR)) does not
cover. Builds on [sjs/flow-mcp](https://github.com/sjsone/SJS.Flow.MCP) and
[sjs/neos-mcp](https://github.com/sjsone/SJS.Neos.MCP).

This README also has the [full setup guide](#setting-up-the-full-mcp-toolset-in-a-neos-project)
for the whole toolset (sjs base packages, this package and
[Sandstorm.MCP.FeatureSet.Media](https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Media)).

## Tools

The prefix `content_edit_` comes from the feature set class name (`ContentEditFeatureSet`).

| Tool | What it does |
|---|---|
| `content_edit_hide_node` / `content_edit_show_node` | Hides or shows a node (`disabled` subtree tag, like the backend's hide checkbox) |
| `content_edit_move_node` | Moves a node `before` / `after` / `into` a reference node |
| `content_edit_soft_remove_node` / `content_edit_restore_node` | Deletes a node like the backend does (`removed` subtree tag); undo with restore until published |
| `content_edit_set_references` | Sets a reference property (the property tools cannot write references) |
| `content_edit_publish_workspace` | Publishes a workspace to its base, **asks before publishing to live** (see below) |

### Safety

- None of the node tools write to the `live` workspace. Changes stay in a workspace, where you can review them in the workspace module.
- There is no hard removal. Soft removals appear as `deleted` in the review list and can be discarded.
- `content_edit_publish_workspace` publishes directly only if the base workspace is **not** live. If the base is live, the first call only returns a preview (the number of changes). The agent must call again with `confirm_publish_to_live: "<workspace name>"`.
- The confirm parameter makes the agent stop and ask, but it cannot prove that a human agreed. The real human check is your MCP client's approval prompt: the tool is marked `destructiveHint`, so **never put it on an auto-approve list**.

## Setting up the full MCP toolset in a Neos project

Tested with Neos 9.1 and PHP 8.3. In the commands, replace `vendor/site` / `Vendor.Site` with the
name and package key of your site package. Run `composer` and `./flow` wherever your project runs
them, e.g. inside the container.

### 1. Add the repositories

The packages are not on Packagist. Composer only reads repositories from the **root**
`composer.json`, so every project needs all four entries:

```sh
composer config repositories.sandstorm-flow-mcp vcs https://github.com/sandstorm/SJS.Flow.MCP
composer config repositories.sandstorm-mcp-feature-set-cr vcs https://github.com/sandstorm/SJS.Neos.MCP.FeatureSet.CR
composer config repositories.sandstorm-mcp-feature-set-content vcs https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Content
composer config repositories.sandstorm-mcp-feature-set-media vcs https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Media
```

### 2. Install the packages

```sh
composer require "sjs/flow-mcp:dev-sandstorm as 1.0.2" "sjs/neos-mcp:^1.0" "sjs/neos-mcp-feature-set-neos:^1.0" "sjs/neos-mcp-feature-set-cr:dev-main" "sjs/neos-mcp-feature-set-resources:^1.0" "sjs/neos-mcp-feature-set-test:^1.0" "sandstorm/mcp-feature-set-content:^0.1" "sandstorm/mcp-feature-set-media:^0.1"
```

- `sjs/flow-mcp` comes from the `sandstorm` branch of our fork. It carries two fixes that are still pending upstream:
  - [sjsone/SJS.Flow.MCP#2](https://github.com/sjsone/SJS.Flow.MCP/pull/2): Flow proxies break with named arguments
  - [sjsone/SJS.Flow.MCP#3](https://github.com/sjsone/SJS.Flow.MCP/pull/3): `disabledTools`

  Once both are released upstream, switch to the Packagist version and drop the fork repository.
- `sjs/neos-mcp-feature-set-test` is optional. It only provides `test_ping` / `test_authenticated_user` for checking the connection.
- **Docker:** if your container runs `composer install` on startup, it will not start while `composer.lock` is out of date. Run composer in a one-off container instead:
  `docker compose run --rm --no-deps --entrypoint composer <service> require …`

### 3. Let your site package load after the feature sets

Add this to `require` in your site package's `composer.json`:

```json
"sandstorm/mcp-feature-set-media": "*"
```

It makes the site package load after the Media package and, through it, after `sjs/flow-mcp`. Your
settings then win the merge. Afterwards run:

```sh
composer update vendor/site
```

### 4. Configure

Create `DistributionPackages/Vendor.Site/Configuration/Settings.MCP.yaml`:

```yaml
SJS:
  Flow:
    MCP:
      server:
        mcp:
          # upstream publish goes straight to live; replaced by content_edit_publish_workspace.
          # upstream delete removes workspaces that still hold changes; leave that to the backend.
          disabledTools:
            - workspace_publish_workspace
            - workspace_delete_workspace

Sandstorm:
  MCP:
    FeatureSet:
      Media:
        resourceFolder: '%FLOW_PATH_DATA%Persistent/McpResources/'
        # empty = soft_remove_migrated_node refuses everything; add pairs when a migration needs them
        removableNodes: []
```

See the [Media README](https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Media#configuration)
for the `removableNodes` format.

### 5. Migrate the database and restart

`sjs/neos-mcp` stores MCP connections and their tokens in the database:

```sh
./flow doctrine:migrate
```

Restart the app or container afterwards: Flow picks up changed composer dependencies only on
startup, so flushing the cache is not enough. Then check the effective configuration:

```sh
./flow configuration:show --path SJS.Flow.MCP.server.mcp.disabledTools
./flow configuration:show --path Sandstorm.MCP.FeatureSet.Media
```

If `disabledTools` shows `[]`, your site package is losing the merge. Check step 3 and restart
again.

### 6. Create a connection token

In the Neos backend, open **MCP → Connections** and click **Create Connection**. Copy the token.

The connection acts as the user who created it. On shared or production systems, use a separate
user that is **not allowed to publish** and has its own workspace, so every change is reviewed
by a human.

### 7. Connect your MCP client

For Claude Code, create `.mcp.json` in the project root:

```json
{
  "mcpServers": {
    "neos-mcp-local": {
      "type": "http",
      "url": "http://localhost:<port>/mcp",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

The file contains the token, so keep it out of git (`echo ".mcp.json" >> .gitignore`). Commit a
`.mcp.json.example` with a placeholder instead.

Check the connection with `claude mcp list`. The server answers even when the token is wrong, so
also call `test_authenticated_user` and check that it shows the expected user.

### 8. Smoke test

- **Tool list must contain:** `content_edit_*`, `media_import_*` and `content_edit_publish_workspace`.
- **Tool list must not contain:** `workspace_publish_workspace` or `workspace_delete_workspace`.
- **Write test:** in a throwaway workspace, hide and then show a *small* text node with `content_edit_hide_node` / `content_edit_show_node`, and check the change in the workspace module.

## License

AGPL-3.0-or-later, like the `sjs/*` packages it extends.
