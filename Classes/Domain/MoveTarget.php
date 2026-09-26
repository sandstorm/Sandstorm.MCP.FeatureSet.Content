<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Domain;

/**
 * The three optional ids that MoveNodeAggregate takes, derived from one {@see MovePosition}
 * and one reference node.
 *
 * Kept free of any Content Repository type on purpose - the whole translation is a handful of
 * id comparisons and is unit tested as such, the same split that {@see SubtreeTagState} uses for
 * the hide/show decision matrix and that RemovableNodeWhitelist in
 * Sandstorm.MCP.FeatureSet.Media uses for the removal safety matrix.
 *
 * Two rules are encoded here that the Neos backend applies and that are easy to miss:
 *
 *  1. The new parent is only handed over when the parent actually CHANGES. That is not
 *     cosmetic: a non-null newParentNodeAggregateId makes the command handler validate the
 *     siblings with requireNodeAggregateToBeChild instead of requireNodeAggregateToBeSibling
 *     and sets $completeSet = true when resolving the interdimensional siblings
 *     (NodeMove::handleMoveNodeAggregate), which changes where unresolvable variants land.
 *     All three UI change classes compute exactly this - see Neos.Neos.Ui
 *     Domain/Model/Changes/MoveBefore, MoveAfter and MoveInto.
 *  2. A derived sibling that turns out to be the moved node itself is dropped, otherwise the
 *     node would be its own anchor. Neos comments this as "we move the node to its current
 *     position", a no-op without dimensions but still worth executing with them.
 */
final readonly class MoveTarget
{
    private function __construct(
        public ?string $newParentNodeAggregateId,
        public ?string $newPrecedingSiblingNodeAggregateId,
        public ?string $newSucceedingSiblingNodeAggregateId,
    ) {
    }

    /**
     * @param string $subjectId the node being moved
     * @param string|null $subjectParentId its current parent, null if it has none
     * @param string $referenceId the node the new position is expressed relative to
     * @param string $referenceParentId the reference node's parent - the new parent
     * @param string|null $precedingSiblingOfReferenceId the node directly in front of the
     *        reference node, null if the reference node is already first
     */
    public static function before(
        string $subjectId,
        ?string $subjectParentId,
        string $referenceId,
        string $referenceParentId,
        ?string $precedingSiblingOfReferenceId,
    ): self {
        return new self(
            newParentNodeAggregateId: self::parentIfChanged($subjectParentId, $referenceParentId),
            newPrecedingSiblingNodeAggregateId: self::unlessSubject($precedingSiblingOfReferenceId, $subjectId),
            newSucceedingSiblingNodeAggregateId: $referenceId,
        );
    }

    /**
     * @param string|null $succeedingSiblingOfReferenceId the node directly behind the
     *        reference node, null if the reference node is already last
     */
    public static function after(
        string $subjectId,
        ?string $subjectParentId,
        string $referenceId,
        string $referenceParentId,
        ?string $succeedingSiblingOfReferenceId,
    ): self {
        return new self(
            newParentNodeAggregateId: self::parentIfChanged($subjectParentId, $referenceParentId),
            newPrecedingSiblingNodeAggregateId: $referenceId,
            newSucceedingSiblingNodeAggregateId: self::unlessSubject($succeedingSiblingOfReferenceId, $subjectId),
        );
    }

    /**
     * No siblings at all, which makes the command append the node as the last child - the same
     * thing MoveInto does in the Neos backend.
     *
     * @param string $referenceId the node that becomes the new parent
     */
    public static function into(?string $subjectParentId, string $referenceId): self
    {
        return new self(
            newParentNodeAggregateId: self::parentIfChanged($subjectParentId, $referenceId),
            newPrecedingSiblingNodeAggregateId: null,
            newSucceedingSiblingNodeAggregateId: null,
        );
    }

    /**
     * True when the node keeps its parent and is only reordered among its siblings. Callers
     * use this for the result message; the command hook in Sandstorm.NodeTypes.Folder also
     * skips its uriPath collision check in exactly this case.
     */
    public function isPureReorder(): bool
    {
        return $this->newParentNodeAggregateId === null;
    }

    private static function parentIfChanged(?string $subjectParentId, string $newParentId): ?string
    {
        return $subjectParentId === $newParentId ? null : $newParentId;
    }

    private static function unlessSubject(?string $siblingId, string $subjectId): ?string
    {
        return $siblingId === $subjectId ? null : $siblingId;
    }
}
