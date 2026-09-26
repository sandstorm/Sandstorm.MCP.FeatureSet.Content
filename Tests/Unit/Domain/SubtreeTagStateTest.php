<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sandstorm\MCP\FeatureSet\Content\Domain\SubtreeTagState;

/**
 * Covers the decision matrix behind hide_node / show_node and soft_remove_node / restore_node.
 *
 * The three states that matter are spelled out here rather than inside the tools, because
 * both content repository commands throw instead of being idempotent: TagSubtree throws
 * SubtreeIsAlreadyTagged (1731167142), UntagSubtree throws SubtreeIsNotTagged (1731167464),
 * and both check with `withoutInherited: true`. Getting the inherited case wrong is what
 * turns a helpful refusal into a raw exception.
 *
 * The matrix is the same for `disabled` and `removed`, which is why it is tested once and
 * used by all four tools.
 */
class SubtreeTagStateTest extends UnitTestCase
{
    private function untagged(): SubtreeTagState
    {
        return new SubtreeTagState(explicitlyTagged: false, effectivelyTagged: false);
    }

    private function explicitlyTagged(): SubtreeTagState
    {
        return new SubtreeTagState(explicitlyTagged: true, effectivelyTagged: true);
    }

    private function taggedViaAncestor(): SubtreeTagState
    {
        return new SubtreeTagState(explicitlyTagged: false, effectivelyTagged: true);
    }

    /** @test */
    public function untaggedNodeCanBeTagged(): void
    {
        self::assertTrue($this->untagged()->canTag());
    }

    /** @test */
    public function untaggedNodeCannotBeUntaggedBecauseThereIsNothingToUntag(): void
    {
        self::assertFalse($this->untagged()->canUntag());
    }

    /** @test */
    public function untaggedNodeIsNotInheritedOnly(): void
    {
        self::assertFalse($this->untagged()->isInheritedOnly());
    }

    /** @test */
    public function explicitlyTaggedNodeCannotBeTaggedAgain(): void
    {
        // Would throw SubtreeIsAlreadyTagged (1731167142) if the tool did not check.
        self::assertFalse($this->explicitlyTagged()->canTag());
    }

    /** @test */
    public function explicitlyTaggedNodeCanBeUntagged(): void
    {
        self::assertTrue($this->explicitlyTagged()->canUntag());
    }

    /** @test */
    public function explicitlyTaggedNodeIsNotInheritedOnly(): void
    {
        self::assertFalse($this->explicitlyTagged()->isInheritedOnly());
    }

    /** @test */
    public function nodeTaggedOnlyViaAncestorIsReportedAsInherited(): void
    {
        self::assertTrue($this->taggedViaAncestor()->isInheritedOnly());
    }

    /** @test */
    public function nodeTaggedOnlyViaAncestorCannotBeUntagged(): void
    {
        // The decisive case: UntagSubtree would throw SubtreeIsNotTagged (1731167464),
        // because there is no tag of its own to remove.
        self::assertFalse($this->taggedViaAncestor()->canUntag());
    }

    /** @test */
    public function nodeTaggedOnlyViaAncestorCanStillGetItsOwnTag(): void
    {
        // Mirrors the content repository: TagSubtree succeeds and adds an explicit tag on top
        // of the inherited one, so untagging the ancestor later leaves this node tagged.
        self::assertTrue($this->taggedViaAncestor()->canTag());
    }
}
