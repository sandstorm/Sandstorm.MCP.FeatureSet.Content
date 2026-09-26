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
 * Makes a hidden node visible again - the counterpart to {@see HideNodeTool}.
 *
 * The case worth knowing about: a node can be invisible without carrying the `disabled` tag
 * itself, namely when an ancestor is hidden and the tag is inherited. UntagSubtree cannot
 * remove an inherited tag and throws SubtreeIsNotTagged (1731167464) in that situation, so
 * this tool detects it first and names the ancestor that actually has to be shown.
 */
class ShowNodeTool extends AbstractSubtreeTagTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'show_node',
            description: 'Makes a hidden node visible again in a non-live workspace by removing the `disabled` '
                . 'subtree tag - the counterpart to hide_node. Safe to repeat: an already visible node is reported '
                . 'as such instead of failing. If the node is only invisible because an ancestor is hidden, this '
                . 'reports which ancestor has to be shown instead, because an inherited tag cannot be removed here.',
            inputSchema: self::createInputSchema(
                'The node_address of the node to make visible again (as returned by other tools)'
            ),
            annotations: new Annotations(
                title: 'Show Node',
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
        $nodeAddress = $this->retrieveNodeAddress($input);
        $contentRepository = $this->getContentRepository($serverContext);
        $subgraph = $this->getSubgraph($contentRepository, $nodeAddress);

        $node = $this->requireNode($subgraph, $nodeAddress);
        $nodeAggregate = $this->requireNodeAggregate($contentRepository, $nodeAddress);
        $dimensionSpacePoint = $this->targetDimensionSpacePoint($node);

        $hiddenState = $this->determineTagState($nodeAggregate, $dimensionSpacePoint, NeosSubtreeTag::disabled());

        if ($hiddenState->isInheritedOnly()) {
            $ancestor = $this->findBlockingAncestor($subgraph, $contentRepository, $nodeAddress, NeosSubtreeTag::disabled());
            $ancestorHint = $ancestor !== null
                ? "Call show_node on {$ancestor->aggregateId->value} (type {$ancestor->nodeTypeName->value}) instead."
                : 'The hidden ancestor could not be determined; inspect the parent chain in the backend.';

            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) carries no `disabled` "
                . 'tag of its own - it is invisible because an ancestor is hidden, and an inherited tag cannot be '
                . 'removed on the descendant. ' . $ancestorHint
            );
        }

        if (!$hiddenState->canUntag()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is already visible "
                . "in workspace {$nodeAddress->workspaceName->value}; nothing to do."
            );
        }

        $command = UntagSubtree::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            coveredDimensionSpacePoint: $dimensionSpacePoint,
            nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            tag: NeosSubtreeTag::disabled()
        );

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Made node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) visible again in "
            . "workspace {$nodeAddress->workspaceName->value}. Descendants that carry their own `disabled` tag "
            . 'stay hidden.'
        );
    }
}
