<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content;

use Neos\Flow\Annotations as Flow;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\HideNodeTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\MoveNodeTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\PublishWorkspaceTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\RestoreNodeTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\SetReferencesTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\ShowNodeTool;
use Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet\SoftRemoveNodeTool;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;

/**
 * Content repository write tools that the upstream CR feature set (sjs/neos-mcp-feature-set-cr)
 * does not cover.
 *
 * The tool name prefix is derived from this class name by
 * {@see AbstractFeatureSet::generateToolCallPrefix()} — NOT from the key used in Settings.yaml:
 * ContentEditFeatureSet => "content_edit" => e.g. `content_edit_set_references`.
 * Renaming this class renames every tool in it.
 */
#[Flow\Scope("singleton")]
class ContentEditFeatureSet extends AbstractFeatureSet
{
    public function initialize(): void
    {
        $this->addTool(SetReferencesTool::class);
        $this->addTool(HideNodeTool::class);
        $this->addTool(MoveNodeTool::class);
        $this->addTool(RestoreNodeTool::class);
        $this->addTool(ShowNodeTool::class);
        $this->addTool(SoftRemoveNodeTool::class);
        $this->addTool(PublishWorkspaceTool::class);
    }
}
