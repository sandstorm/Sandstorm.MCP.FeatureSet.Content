<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;

/**
 * Brings a soft removed node back - the counterpart to {@see SoftRemoveNodeTool}.
 *
 * Same trap as in {@see ShowNodeTool}: a node can be gone without carrying the `removed` tag
 * itself, namely when an ancestor was removed and the tag is inherited. UntagSubtree cannot
 * remove an inherited tag and throws SubtreeIsNotTagged (1731167464), so this tool detects the
 * case first and names the ancestor that actually has to be restored.
 *
 * Deliberate divergence from Neos: Neos.Workspace.Ui RestoreController untags with
 * STRATEGY_ALL_VARIANTS, because the trash bin restores a whole aggregate no matter which
 * dimension the editor is in. This tool uses STRATEGY_ALL_SPECIALIZATIONS so that it is the
 * exact inverse of soft_remove_node.
 */
class RestoreNodeTool extends AbstractSubtreeTagTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'restore_node',
            description: 'Brings a soft removed node back in a non-live workspace by taking off the `removed` '
                . 'subtree tag - the counterpart to soft_remove_node. It returns to its original position. '
                . 'A removed node is invisible to every other tool, so you cannot look its id up any more: take it '
                . 'from the answer of soft_remove_node, or from the trash bin in the Neos backend. Only works '
                . 'while the removal is unpublished. Safe to repeat: a node that is not removed is reported as '
                . 'such instead of failing. If the node is only gone because an ancestor was removed, this reports '
                . 'which ancestor has to be restored instead, because an inherited tag cannot be removed here.',
            inputSchema: self::createInputSchema(
                'The node_address of the node to restore (as reported by soft_remove_node)'
            ),
            annotations: new Annotations(
                title: 'Restore Node',
                destructiveHint: false,
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
        $this->requireNonLiveWorkspace($nodeAddress, 'Restoring nodes');

        $contentRepository = $this->getContentRepository($serverContext);
        // createEmpty() is what makes the removed node findable at all here.
        $subgraph = $this->getSubgraph($contentRepository, $nodeAddress);

        $node = $this->requireNode($subgraph, $nodeAddress);
        $nodeAggregate = $this->requireNodeAggregate($contentRepository, $nodeAddress);

        // The same covered dimension space point soft_remove_node tagged with, see the comment
        // there - not the node's origin.
        $dimensionSpacePoint = $nodeAddress->dimensionSpacePoint;
        $removed = NeosSubtreeTag::removed();

        $tagState = $this->determineTagState($nodeAggregate, $dimensionSpacePoint, $removed);

        if ($tagState->isInheritedOnly()) {
            $ancestor = $this->findBlockingAncestor($subgraph, $contentRepository, $nodeAddress, $removed);
            $ancestorHint = $ancestor !== null
                ? "Call restore_node on {$ancestor->aggregateId->value} (type {$ancestor->nodeTypeName->value}) instead."
                : 'The removed ancestor could not be determined; inspect the parent chain in the backend.';

            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) carries no `removed` "
                . 'tag of its own - it is gone because an ancestor was removed, and an inherited tag cannot be '
                . 'removed on the descendant. ' . $ancestorHint
            );
        }

        if (!$tagState->canUntag()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is not removed "
                . "in workspace {$nodeAddress->workspaceName->value}; nothing to do."
            );
        }

        $command = UntagSubtree::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            coveredDimensionSpacePoint: $dimensionSpacePoint,
            nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            tag: $removed
        );

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Restored node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) in workspace "
            . "{$nodeAddress->workspaceName->value}; it is back at its original position. Descendants that carry "
            . 'their own `removed` tag stay removed.'
        );
    }
}
