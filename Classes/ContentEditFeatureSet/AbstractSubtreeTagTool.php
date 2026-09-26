<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Sandstorm\MCP\FeatureSet\Content\Domain\SubtreeTagState;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;

/**
 * Shared plumbing for the four tag based tools - hide_node / show_node (`disabled`) and
 * soft_remove_node / restore_node (`removed`) - on top of {@see AbstractContentEditTool}.
 *
 * What is specific to subtree tagging and therefore lives here:
 *
 *  - the CR commands are not idempotent (SubtreeIsAlreadyTagged 1731167142 /
 *    SubtreeIsNotTagged 1731167464), which is why every caller goes through
 *    {@see determineTagState()} first.
 *  - an inherited tag cannot be untagged on the descendant, so both untagging tools have to
 *    name the ancestor that actually carries it, see {@see findBlockingAncestor()}.
 *
 * The dimension space point is deliberately NOT decided here, because Neos itself does not
 * decide it uniformly: the hide tools pass the node's ORIGIN dimension space point, mirroring
 * Neos.Neos.Ui Changes/Property.php::handleHiddenPropertyChange, while the removal tools pass
 * the covered one from the NodeAddress, mirroring Neos.Neos.Ui Changes/Remove.php. Each tool
 * passes its own point into the methods below, and must use the same point for the guard and
 * for the command so the two can never disagree.
 */
abstract class AbstractSubtreeTagTool extends AbstractContentEditTool
{
    final protected static function createInputSchema(string $description): ObjectSchema
    {
        return self::nodeAddressSchema($description);
    }

    /**
     * @param array<string,mixed> $input
     */
    final protected function retrieveNodeAddress(array $input): NodeAddress
    {
        $nodeAddress = $this->parseNodeAddress($input);

        // Never touch live. Visibility changes must be reviewable in a workspace.
        $this->requireNonLiveWorkspace($nodeAddress, 'Changing node visibility');

        return $nodeAddress;
    }

    final protected function determineTagState(NodeAggregate $nodeAggregate, DimensionSpacePoint $dimensionSpacePoint, SubtreeTag $tag): SubtreeTagState
    {
        return new SubtreeTagState(
            explicitlyTagged: $nodeAggregate
                ->getCoveredDimensionsTaggedBy($tag, withoutInherited: true)
                ->contains($dimensionSpacePoint),
            effectivelyTagged: $nodeAggregate
                ->getCoveredDimensionsTaggedBy($tag, withoutInherited: false)
                ->contains($dimensionSpacePoint),
        );
    }

    /**
     * Walks up the parent chain and returns the closest ancestor that carries the tag
     * explicitly - the node that actually has to be untagged. Null if none is found (should
     * not happen when SubtreeTagState::isInheritedOnly() is true, but the walk is bounded and
     * must not assume).
     *
     * Known limitation: each ancestor is tested against its OWN origin dimension space point,
     * so in a multi dimension setup an ancestor tagged only in a fallback can be missed. This
     * only feeds a hint in a refusal message, never a guard.
     */
    final protected function findBlockingAncestor(ContentSubgraphInterface $subgraph, ContentRepository $contentRepository, NodeAddress $nodeAddress, SubtreeTag $tag): ?Node
    {
        $contentGraph = $contentRepository->getContentGraph($nodeAddress->workspaceName);
        $currentId = $nodeAddress->aggregateId;

        // Bounded walk; guards against cycles, same approach as RemovableNodeWhitelist.
        for ($i = 0; $i < 50; $i++) {
            $parent = $subgraph->findParentNode($currentId);
            if ($parent === null) {
                return null;
            }

            $parentAggregate = $contentGraph->findNodeAggregateById($parent->aggregateId);
            if (
                $parentAggregate !== null
                && $parentAggregate
                    ->getCoveredDimensionsTaggedBy($tag, withoutInherited: true)
                    ->contains($this->targetDimensionSpacePoint($parent))
            ) {
                return $parent;
            }

            $currentId = $parent->aggregateId;
        }

        return null;
    }
}
