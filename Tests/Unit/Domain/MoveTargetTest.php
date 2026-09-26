<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sandstorm\MCP\FeatureSet\Content\Domain\MoveTarget;

/**
 * Covers the translation from "before/after/into this node" into the three optional ids that
 * MoveNodeAggregate takes.
 *
 * Worth its own test because the mapping is counter-intuitive in both directions: "move X
 * BEFORE Y" becomes succeedingSibling = Y, and the new parent has to be withheld when it did
 * not actually change - passing it anyway switches the command handler from
 * requireNodeAggregateToBeSibling to requireNodeAggregateToBeChild and sets $completeSet =
 * true when resolving interdimensional siblings.
 */
class MoveTargetTest extends UnitTestCase
{
    private const SUBJECT = 'subject';
    private const OLD_PARENT = 'old-parent';
    private const NEW_PARENT = 'new-parent';
    private const REFERENCE = 'reference';

    /** @test */
    public function beforePutsTheReferenceNodeIntoTheSucceedingSiblingSlot(): void
    {
        // The inversion that callers get wrong: "before Y" means "Y comes after me".
        $target = MoveTarget::before(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            precedingSiblingOfReferenceId: null,
        );

        self::assertSame(self::REFERENCE, $target->newSucceedingSiblingNodeAggregateId);
        self::assertNull($target->newPrecedingSiblingNodeAggregateId);
    }

    /** @test */
    public function beforeAlsoPassesThePrecedingSiblingOfTheReferenceNode(): void
    {
        // Both anchors are supplied deliberately: the command falls back to the other one in
        // dimension space points the first does not cover.
        $target = MoveTarget::before(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            precedingSiblingOfReferenceId: 'node-before-reference',
        );

        self::assertSame('node-before-reference', $target->newPrecedingSiblingNodeAggregateId);
        self::assertSame(self::REFERENCE, $target->newSucceedingSiblingNodeAggregateId);
    }

    /** @test */
    public function afterPutsTheReferenceNodeIntoThePrecedingSiblingSlot(): void
    {
        $target = MoveTarget::after(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            succeedingSiblingOfReferenceId: 'node-after-reference',
        );

        self::assertSame(self::REFERENCE, $target->newPrecedingSiblingNodeAggregateId);
        self::assertSame('node-after-reference', $target->newSucceedingSiblingNodeAggregateId);
    }

    /** @test */
    public function reorderingUnderTheSameParentWithholdsTheParentId(): void
    {
        $target = MoveTarget::after(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            succeedingSiblingOfReferenceId: null,
        );

        self::assertNull($target->newParentNodeAggregateId);
        self::assertTrue($target->isPureReorder());
    }

    /** @test */
    public function movingUnderANewParentPassesTheParentId(): void
    {
        $target = MoveTarget::after(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::NEW_PARENT,
            succeedingSiblingOfReferenceId: null,
        );

        self::assertSame(self::NEW_PARENT, $target->newParentNodeAggregateId);
        self::assertFalse($target->isPureReorder());
    }

    /** @test */
    public function aNodeWithoutAParentAlwaysGetsTheNewParentId(): void
    {
        $target = MoveTarget::into(subjectParentId: null, referenceId: self::NEW_PARENT);

        self::assertSame(self::NEW_PARENT, $target->newParentNodeAggregateId);
    }

    /** @test */
    public function derivedPrecedingSiblingIsDroppedWhenItIsTheMovedNodeItself(): void
    {
        // Moving a node one step down: the node in front of the reference IS the node being
        // moved. Passing it would make the node its own anchor.
        $target = MoveTarget::before(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            precedingSiblingOfReferenceId: self::SUBJECT,
        );

        self::assertNull($target->newPrecedingSiblingNodeAggregateId);
        self::assertSame(self::REFERENCE, $target->newSucceedingSiblingNodeAggregateId);
    }

    /** @test */
    public function derivedSucceedingSiblingIsDroppedWhenItIsTheMovedNodeItself(): void
    {
        // The mirror case: moving a node one step up.
        $target = MoveTarget::after(
            subjectId: self::SUBJECT,
            subjectParentId: self::OLD_PARENT,
            referenceId: self::REFERENCE,
            referenceParentId: self::OLD_PARENT,
            succeedingSiblingOfReferenceId: self::SUBJECT,
        );

        self::assertNull($target->newSucceedingSiblingNodeAggregateId);
        self::assertSame(self::REFERENCE, $target->newPrecedingSiblingNodeAggregateId);
    }

    /** @test */
    public function intoPassesNoSiblingsSoTheNodeIsAppendedLast(): void
    {
        $target = MoveTarget::into(subjectParentId: self::OLD_PARENT, referenceId: self::NEW_PARENT);

        self::assertNull($target->newPrecedingSiblingNodeAggregateId);
        self::assertNull($target->newSucceedingSiblingNodeAggregateId);
        self::assertSame(self::NEW_PARENT, $target->newParentNodeAggregateId);
    }

    /** @test */
    public function intoTheCurrentParentIsAPureReorderToTheEnd(): void
    {
        // "into" its existing parent means "move to the end of where it already is".
        $target = MoveTarget::into(subjectParentId: self::OLD_PARENT, referenceId: self::OLD_PARENT);

        self::assertNull($target->newParentNodeAggregateId);
        self::assertTrue($target->isPureReorder());
    }
}
