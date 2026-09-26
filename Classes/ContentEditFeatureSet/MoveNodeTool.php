<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindPrecedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Sandstorm\MCP\FeatureSet\Content\Domain\MovePosition;
use Sandstorm\MCP\FeatureSet\Content\Domain\MoveTarget;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * Moves a node, the way dragging it in the Neos backend does.
 *
 * The tool deliberately does NOT expose the three optional ids of MoveNodeAggregate
 * (new parent / preceding sibling / succeeding sibling). It takes one reference node and one
 * of three intents instead, and derives the triple in {@see MoveTarget}. Reason: the raw
 * parameters interact, and `preceding` vs `succeeding` is exactly the distinction a caller
 * inverts - "move X before Y" is expressed as succeedingSibling = Y, which reads backwards.
 *
 * Reordering a run of nodes in front of an anchor is then: move the first one `before` the
 * anchor, and every following one `after` its predecessor.
 */
class MoveNodeTool extends AbstractContentEditTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'move_node',
            description: 'Moves a node to a new position in a non-live workspace, like dragging it in the Neos '
                . 'backend. Give the node to move plus ONE reference node and a position: `before` and `after` '
                . 'place it next to the reference node (moving it under the reference node\'s parent if that '
                . 'differs from its current one), `into` appends it as the LAST child of the reference node. '
                . 'To reorder a run of nodes in front of an anchor, move the first one `before` the anchor and '
                . 'each following one `after` its predecessor. Works for content nodes and documents alike, but '
                . 'not for tethered nodes such as auto-created content collections. The change stays in the '
                . 'workspace until it is published.',
            inputSchema: self::nodeAddressSchema(
                'The node_address of the node to move (as returned by other tools)',
                [
                    'position' => (new StringSchema(
                        description: 'Where to put the node relative to reference_node_id: '
                            . '"before" = directly in front of it, "after" = directly behind it, '
                            . '"into" = as its last child.',
                        enum: MovePosition::allValues()
                    ))->required(),
                    'reference_node_id' => (new StringSchema(
                        description: 'NodeAggregateId of the node the new position is relative to. '
                            . 'With "into" this is the new parent; with "before"/"after" it is the new sibling.'
                    ))->required(),
                ]
            ),
            annotations: new Annotations(
                title: 'Move Node',
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
        $this->requireNonLiveWorkspace($nodeAddress, 'Moving nodes');

        $position = $this->parsePosition($input);
        $referenceNodeId = $this->parseReferenceNodeId($input);

        if ($referenceNodeId->equals($nodeAddress->aggregateId)) {
            // Left to the content repository this surfaces as NodeAggregateIsDescendant,
            // which does not describe the actual mistake.
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} cannot be positioned relative to itself. "
                . 'Pass a different node as reference_node_id.'
            );
        }

        $contentRepository = $this->getContentRepository($serverContext);
        $subgraph = $this->getSubgraph($contentRepository, $nodeAddress);

        $node = $this->requireNode($subgraph, $nodeAddress);
        $nodeAggregate = $this->requireNodeAggregate($contentRepository, $nodeAddress);

        // Tethered nodes (auto-created children such as `main` content collections) are
        // rejected by requireNodeAggregateToBeUntethered before the move is applied. Say so in
        // a way the caller can act on.
        if ($nodeAggregate->classification->isTethered()) {
            return Content::text(
                "Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is a tethered node "
                . 'and cannot be moved - it is auto-created at a fixed position by its parent node type. '
                . 'Move the document that contains it, or the individual content nodes inside it.'
            );
        }

        $referenceNode = $subgraph->findNodeById($referenceNodeId);
        if ($referenceNode === null) {
            return Content::text(
                "Could not find the reference node {$referenceNodeId->value} in workspace "
                . "{$nodeAddress->workspaceName->value}."
            );
        }

        $currentParent = $subgraph->findParentNode($nodeAddress->aggregateId);
        $currentParentId = $currentParent?->aggregateId->value;

        $moveTarget = $this->determineMoveTarget($subgraph, $position, $nodeAddress->aggregateId, $currentParentId, $referenceNode);
        if ($moveTarget === null) {
            return Content::text(
                "The reference node {$referenceNodeId->value} (type {$referenceNode->nodeTypeName->value}) has no "
                . 'parent, so nothing can be placed next to it. Use position "into" to move the node inside it.'
            );
        }

        $command = MoveNodeAggregate::create(
            $nodeAddress->workspaceName,
            // MoveNodeAggregate documents this as one of the *covered* dimension space points,
            // and NodeAddress carries a covered one - so unlike the subtree tagging tools, this
            // must NOT go through targetDimensionSpacePoint()/originDimensionSpacePoint.
            $nodeAddress->dimensionSpacePoint,
            $nodeAddress->aggregateId,
            $this->determineRelationDistributionStrategy($contentRepository, $node),
            $this->asNodeAggregateId($moveTarget->newParentNodeAggregateId),
            $this->asNodeAggregateId($moveTarget->newPrecedingSiblingNodeAggregateId),
            $this->asNodeAggregateId($moveTarget->newSucceedingSiblingNodeAggregateId),
        );

        $placement = match ($position) {
            MovePosition::BEFORE => "directly in front of {$referenceNodeId->value}",
            MovePosition::AFTER => "directly behind {$referenceNodeId->value}",
            MovePosition::INTO => "as the last child of {$referenceNodeId->value}",
        };

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Moved node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) {$placement} in "
            . "workspace {$nodeAddress->workspaceName->value}"
            . ($moveTarget->isPureReorder()
                ? ', keeping its current parent.'
                : ' and under a new parent. Its descendants moved with it.')
        );
    }

    /**
     * Null when the requested position needs a sibling slot but the reference node has no
     * parent to provide one.
     */
    private function determineMoveTarget(
        ContentSubgraphInterface $subgraph,
        MovePosition $position,
        NodeAggregateId $subjectId,
        ?string $currentParentId,
        Node $referenceNode,
    ): ?MoveTarget {
        if ($position === MovePosition::INTO) {
            return MoveTarget::into($currentParentId, $referenceNode->aggregateId->value);
        }

        $referenceParent = $subgraph->findParentNode($referenceNode->aggregateId);
        if ($referenceParent === null) {
            return null;
        }

        // Both siblings are supplied, as the Neos backend does: the command uses the second one
        // as a fallback in dimension space points the first does not cover, which is why
        // MoveNodeAggregate accepts both at once.
        if ($position === MovePosition::BEFORE) {
            return MoveTarget::before(
                subjectId: $subjectId->value,
                subjectParentId: $currentParentId,
                referenceId: $referenceNode->aggregateId->value,
                referenceParentId: $referenceParent->aggregateId->value,
                precedingSiblingOfReferenceId: $this->findAdjacentSibling($subgraph, $referenceNode, preceding: true),
            );
        }

        return MoveTarget::after(
            subjectId: $subjectId->value,
            subjectParentId: $currentParentId,
            referenceId: $referenceNode->aggregateId->value,
            referenceParentId: $referenceParent->aggregateId->value,
            succeedingSiblingOfReferenceId: $this->findAdjacentSibling($subgraph, $referenceNode, preceding: false),
        );
    }

    /**
     * The node immediately before or after the reference node among its siblings, or null at
     * the ends. findPreceding-/findSucceedingSiblingNodes return the siblings relative to and
     * excluding the given node, so one result is all that is needed.
     */
    private function findAdjacentSibling(ContentSubgraphInterface $subgraph, Node $referenceNode, bool $preceding): ?string
    {
        $pagination = Pagination::fromLimitAndOffset(1, 0);

        $siblings = $preceding
            ? $subgraph->findPrecedingSiblingNodes($referenceNode->aggregateId, FindPrecedingSiblingNodesFilter::create(pagination: $pagination))
            : $subgraph->findSucceedingSiblingNodes($referenceNode->aggregateId, FindSucceedingSiblingNodesFilter::create(pagination: $pagination));

        return $siblings->first()?->aggregateId->value;
    }

    /**
     * The strategy comes from the node type, not from a hard-coded default, because Neos
     * itself configures it per type in Neos.Neos.Ui/Configuration/NodeTypes.yaml:
     * `gatherAll` for Neos.Neos:Document, `scatter` for Neos.Neos:Content. Reading it here is
     * what makes this tool behave like the backend rather than like a guess; see
     * Neos.Neos.Ui Domain/Model/Changes/MoveAfter, which resolves it the same way.
     *
     * Unlike the UI we do not throw on a missing or unparseable value - a tool refusing to
     * move a node over a node type configuration detail helps nobody, and gatherAll is the
     * content repository's own default.
     */
    private function determineRelationDistributionStrategy(ContentRepository $contentRepository, Node $node): RelationDistributionStrategy
    {
        $rawStrategy = $contentRepository
            ->getNodeTypeManager()
            ->getNodeType($node->nodeTypeName)
            ?->getConfiguration('options.moveNodeStrategy');

        if (!\is_string($rawStrategy)) {
            return RelationDistributionStrategy::default();
        }

        return RelationDistributionStrategy::tryFrom($rawStrategy) ?? RelationDistributionStrategy::default();
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parsePosition(array $input): MovePosition
    {
        $rawPosition = $input['position'] ?? null;
        $position = \is_string($rawPosition) ? MovePosition::tryFrom($rawPosition) : null;

        if ($position === null) {
            throw new \InvalidArgumentException(sprintf(
                'position must be one of "%s", got %s.',
                implode('", "', MovePosition::allValues()),
                \is_string($rawPosition) ? '"' . $rawPosition . '"' : get_debug_type($rawPosition)
            ));
        }

        return $position;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseReferenceNodeId(array $input): NodeAggregateId
    {
        $rawId = $input['reference_node_id'] ?? null;
        if (!\is_string($rawId) || $rawId === '') {
            throw new \InvalidArgumentException('reference_node_id is required and must be a NodeAggregateId string.');
        }

        return NodeAggregateId::fromString($rawId);
    }

    private function asNodeAggregateId(?string $id): ?NodeAggregateId
    {
        return $id === null ? null : NodeAggregateId::fromString($id);
    }
}
