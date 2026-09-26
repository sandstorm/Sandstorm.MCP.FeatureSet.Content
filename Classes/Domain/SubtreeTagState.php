<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Domain;

/**
 * Whether a node aggregate carries one subtree tag in one dimension space point, and how.
 *
 * Neos expresses both "hidden" (`disabled`) and "removed" (`removed`) as inherited subtree
 * tags, so there are three states, not two, and the difference decides what the tagging tools
 * may do:
 *
 *  - not tagged at all             -> tagging works, untagging is a no-op
 *  - explicitly tagged             -> tagging is a no-op, untagging works
 *  - only tagged via an ancestor   -> tagging works (adds an own tag), untagging is IMPOSSIBLE
 *
 * The last case is the one worth the extra class. `UntagSubtree` checks with
 * `withoutInherited: true` and throws SubtreeIsNotTagged (1731167464) when the tag is merely
 * inherited, so `show_node` / `restore_node` have to recognise it up front and tell the caller
 * to act on the ancestor instead of forwarding a confusing exception.
 *
 * Kept free of any Content Repository type on purpose: the whole decision matrix is two
 * booleans and is unit tested as such, the same split that {@see RemovableNodeWhitelist} in
 * Sandstorm.MCP.FeatureSet.Media uses for the removal safety matrix. It is deliberately
 * tag-agnostic - `disabled` and `removed` behave identically here, which is why the four tools
 * built on it share one matrix instead of one each.
 */
final readonly class SubtreeTagState
{
    /**
     * @param bool $explicitlyTagged the aggregate carries the tag itself
     *                               (getCoveredDimensionsTaggedBy(..., withoutInherited: true))
     * @param bool $effectivelyTagged the aggregate is tagged at all, own tag or inherited
     *                                (getCoveredDimensionsTaggedBy(..., withoutInherited: false))
     */
    public function __construct(
        public bool $explicitlyTagged,
        public bool $effectivelyTagged,
    ) {
    }

    /**
     * Tagged only because an ancestor is tagged - the node has no tag of its own to remove.
     */
    public function isInheritedOnly(): bool
    {
        return $this->effectivelyTagged && !$this->explicitlyTagged;
    }

    /**
     * False means the node already carries its own tag; tagging again would throw
     * SubtreeIsAlreadyTagged (1731167142).
     */
    public function canTag(): bool
    {
        return !$this->explicitlyTagged;
    }

    /**
     * True only if there is an own tag to remove. An inherited tag cannot be untagged here.
     */
    public function canUntag(): bool
    {
        return $this->explicitlyTagged;
    }
}
