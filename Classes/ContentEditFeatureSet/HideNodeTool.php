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
 * Hides a node the same way the hide checkbox in the Neos backend does.
 *
 * There is deliberately no `_hidden` property involved: in Neos 9 `_hidden` is virtual, only
 * declared as an inspector control on the Neos.Neos:Hidable mixin, and visibility actually
 * hangs off the `disabled` subtree tag. Writing a property of that name (which
 * content_add_content happily accepts) creates a property nobody reads and leaves the node
 * visible.
 *
 * Works on any node, documents included - TagSubtree does not distinguish, and neither does
 * the backend.
 */
class HideNodeTool extends AbstractSubtreeTagTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'hide_node',
            description: 'Hides a node in a non-live workspace, exactly like the hide checkbox in the Neos backend '
                . '(it applies the `disabled` subtree tag). Works for content nodes and documents alike. '
                . 'The tag is inherited, so all descendants are hidden with it. Do NOT try to hide a node by setting '
                . 'a `_hidden` property - that property is virtual and writing it has no effect on visibility. '
                . 'Safe to repeat: an already hidden node is reported as such instead of failing. '
                . 'Reversible with show_node, and the change stays in the workspace until it is published.',
            inputSchema: self::createInputSchema(
                'The node_address of the node to hide (as returned by other tools)'
            ),
            annotations: new Annotations(
                title: 'Hide Node',
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

        // Tethered nodes (auto-created children such as `main` content collections) are
        // rejected by Neos before the command handler even runs
        // (NeosSubtreeTaggingConstraintChecks, 1741161426). Say so in a way the caller can act on.
        if ($nodeAggregate->classification->isTethered()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is a tethered node "
                . 'and cannot be hidden - Neos forbids disabling auto-created child nodes such as content '
                . 'collections. Hide the document that contains it, or the individual content nodes inside it.'
            );
        }

        $hiddenState = $this->determineTagState($nodeAggregate, $dimensionSpacePoint, NeosSubtreeTag::disabled());

        if (!$hiddenState->canTag()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is already hidden "
                . "in workspace {$nodeAddress->workspaceName->value}; nothing to do."
            );
        }

        $command = TagSubtree::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            coveredDimensionSpacePoint: $dimensionSpacePoint,
            nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            tag: NeosSubtreeTag::disabled()
        );

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Hid node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) in workspace "
            . "{$nodeAddress->workspaceName->value}. Descendants are hidden with it, because the tag is inherited."
        );
    }
}
