<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Domain;

/**
 * Where a moved node ends up, relative to a single reference node.
 *
 * This is the tool's vocabulary, not the content repository's. MoveNodeAggregate speaks in
 * terms of a new parent plus a preceding and a succeeding sibling, three optional ids that
 * interact; `preceding` vs `succeeding` in particular is the distinction callers invert most
 * often. {@see MoveTarget} translates one of these three intents into that triple.
 */
enum MovePosition: string
{
    /** Directly in front of the reference node, under the reference node's parent. */
    case BEFORE = 'before';

    /** Directly behind the reference node, under the reference node's parent. */
    case AFTER = 'after';

    /** As the last child of the reference node. */
    case INTO = 'into';

    /**
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
