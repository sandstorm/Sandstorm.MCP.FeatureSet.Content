# Sandstorm.MCP.FeatureSet.Content

MCP tools for Neos 9 content repository write operations that the upstream CR feature set
([sjs/neos-mcp-feature-set-cr](https://github.com/sjsone/SJS.Neos.MCP.FeatureSet.CR)) does not
cover. Builds on [sjs/flow-mcp](https://github.com/sjsone/SJS.Flow.MCP) and
[sjs/neos-mcp](https://github.com/sjsone/SJS.Neos.MCP).

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

## Installation

The package is not on Packagist. Add the VCS repositories to your **root** `composer.json`:

```json
"repositories": {
  "sandstorm-flow-mcp": { "type": "vcs", "url": "https://github.com/sandstorm/SJS.Flow.MCP" },
  "sandstorm-mcp-feature-set-content": { "type": "vcs", "url": "https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Content" }
},
"require": {
  "sjs/flow-mcp": "dev-sandstorm as 1.0.2",
  "sandstorm/mcp-feature-set-content": "^0.1"
}
```

The `sandstorm` branch of the `sjs/flow-mcp` fork carries two fixes that are still pending upstream: the Flow proxy / named-arguments fix and `disabledTools`.

The feature set registers itself on the default server (`SJS.Flow.MCP.server.mcp.featureSets.content_edit`).

### Recommended configuration

Upstream's `workspace_publish_workspace` publishes to live without asking. Upstream's `workspace_delete_workspace` deletes workspaces that still hold unpublished changes; the backend refuses to do that. Disable both in your site package, which must load after `sjs/flow-mcp`:

```yaml
SJS:
  Flow:
    MCP:
      server:
        mcp:
          disabledTools:
            - workspace_publish_workspace
            - workspace_delete_workspace
```

## License

AGPL-3.0-or-later, like the `sjs/*` packages it extends.
