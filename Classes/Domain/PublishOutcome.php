<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Domain;

/**
 * What publish_workspace does with a request, as decided by {@see PublishDecision}.
 */
enum PublishOutcome
{
    /** The workspace to publish is live itself - there is nothing above it. */
    case REFUSE_LIVE;

    /** A root workspace other than live - it has no base workspace to publish to. */
    case REFUSE_ROOT;

    /** No pending changes; PublishWorkspace would be skipped by the content repository anyway. */
    case NOTHING_TO_PUBLISH;

    /** The target is live and the caller did not confirm - answer with a preview, publish nothing. */
    case NEEDS_CONFIRMATION;

    /** The target is live and the confirmation names a different workspace - refuse. */
    case CONFIRMATION_MISMATCH;

    /** Go ahead. */
    case PUBLISH;
}
