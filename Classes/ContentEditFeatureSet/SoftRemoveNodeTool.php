<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;

/**
 * Deletes a node the way the delete button in the Neos backend does.
 *
 * "Soft" describes the mechanism, not the outcome: the node is tagged `removed` instead of
 * being erased, and publishing the workspace really does delete it - Neos runs
 * SoftRemovalGarbageCollector after every publish/discard/rebase and issues the actual
 * RemoveNodeAggregate in live once no other workspace still depends on the node.
 *
 * Why not RemoveNodeAggregate directly:
 * a hard removal on a non-live workspace destroys the hierarchy the workspace module needs to
 * group changes by document. Verified in this project - the overview counted "5 removed" while
 * the review page reported "has no unpublished changes", because
 * WorkspaceController::computeSiteChanges() resolves every change through the subgraph and
 * silently drops the ones it cannot find. Neos.Neos.Ui Changes/Remove.php carries the same
 * reasoning and tags instead.
 */
class SoftRemoveNodeTool extends AbstractSubtreeTagTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'soft_remove_node',
            description: 'Removes a node in a non-live workspace, exactly like the delete button in the Neos '
                . 'backend (it applies the `removed` subtree tag). This is the normal way to delete content in '
                . 'Neos 9 - use it instead of remove_content (unimplemented). "Soft" is about HOW, not WHETHER: publishing the '
                . 'workspace really does delete the node, permanently. Until then it is reversible with '
                . 'restore_node. Descendants are removed with it, because the tag is inherited. Note that the node '
                . 'disappears from every other tool immediately - it will no longer be listed among its parent\'s '
                . 'children - so keep the node id from this tool\'s answer if you may want to restore it. '
                . 'Works for content nodes and documents alike, but not for tethered or root nodes. '
                . 'Safe to repeat: an already removed node is reported as such instead of failing.',
            inputSchema: self::createInputSchema(
                'The node_address of the node to remove (as returned by other tools)'
            ),
            annotations: new Annotations(
                title: 'Soft Remove Node',
                destructiveHint: true,
                idempotentHint: true
            ),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $nodeAddress = $this->parseNodeAddress($input);
        $this->requireNonLiveWorkspace($nodeAddress, 'Removing nodes');

        $contentRepository = $this->getContentRepository($serverContext);
        // The subgraph must not apply visibility constraints: withoutRestrictions() still
        // excludes the `removed` tag, so an already removed node would look as if it had never
        // existed. getSubgraph() uses createEmpty() for exactly this reason.
        $subgraph = $this->getSubgraph($contentRepository, $nodeAddress);

        $node = $this->requireNode($subgraph, $nodeAddress);
        $nodeAggregate = $this->requireNodeAggregate($contentRepository, $nodeAddress);

        // The COVERED dimension space point from the node address, NOT the node's origin.
        // This is the one thing not to copy from HideNodeTool: Neos splits the same way, with
        // Neos.Neos.Ui Changes/Property.php using the origin for `disabled` and Changes/Remove.php
        // using $subject->dimensionSpacePoint for `removed`. The guard below uses the same point
        // as the command, so the two cannot disagree.
        $dimensionSpacePoint = $nodeAddress->dimensionSpacePoint;

        // Both rejections come from NeosSubtreeTaggingConstraintChecks and would otherwise
        // arrive as NodeAggregateIsTethered (1741161426) / NodeAggregateIsRoot (1741162636).
        if ($nodeAggregate->classification->isTethered()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is a tethered node "
                . 'and cannot be removed - Neos forbids removing auto-created child nodes such as content '
                . 'collections. Remove the document that contains it, or the individual content nodes inside it.'
            );
        }

        if ($nodeAggregate->classification->isRoot()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is a root node of the "
                . 'content repository and cannot be removed here. Sites are removed through the Neos Sites module.'
            );
        }

        $tagState = $this->determineTagState($nodeAggregate, $dimensionSpacePoint, NeosSubtreeTag::removed());

        if (!$tagState->canTag()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is already removed "
                . "in workspace {$nodeAddress->workspaceName->value}; nothing to do."
            );
        }

        $command = TagSubtree::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            coveredDimensionSpacePoint: $dimensionSpacePoint,
            nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            tag: NeosSubtreeTag::removed()
        );

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Removed node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) in workspace "
            . "{$nodeAddress->workspaceName->value}. Descendants are removed with it, because the tag is "
            . 'inherited. The node is no longer listed among its parent\'s children; to bring it back, call '
            . "restore_node with the node id {$nodeAddress->aggregateId->value}. Publishing the workspace makes "
            . 'the removal permanent.'
        );
    }
}
