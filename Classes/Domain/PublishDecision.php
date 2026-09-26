<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Domain;

/**
 * Whether publish_workspace may publish a workspace right now.
 *
 * Publishing into a non-live base workspace is harmless for visitors and happens directly.
 * Publishing into live is not, so it takes two calls: the first one only returns a preview, the
 * second one has to repeat the workspace name in `confirm_publish_to_live`. That forces the agent
 * to stop and ask - it does NOT prove that a human agreed, because the agent could fill in the
 * parameter on its own. The actual human-in-the-loop is the MCP client's approval prompt for this
 * (destructiveHint) tool, which is why it must never be put on an auto-approve list.
 *
 * Kept free of any Content Repository type on purpose, like {@see SubtreeTagState}: the whole
 * decision is a handful of scalars and is unit tested as such.
 */
final readonly class PublishDecision
{
    /**
     * @param string $workspaceName the workspace to publish
     * @param string|null $baseWorkspaceName its base workspace, null for root workspaces
     * @param int $changeCount pending changes in the workspace (ChangeFinder)
     * @param string|null $confirmation the value of `confirm_publish_to_live`, null if not given
     */
    public function __construct(
        public string $workspaceName,
        public ?string $baseWorkspaceName,
        public int $changeCount,
        public ?string $confirmation,
    ) {
    }

    public function targetIsLive(): bool
    {
        return $this->baseWorkspaceName === 'live';
    }

    public function outcome(): PublishOutcome
    {
        if ($this->workspaceName === 'live') {
            return PublishOutcome::REFUSE_LIVE;
        }
        if ($this->baseWorkspaceName === null) {
            return PublishOutcome::REFUSE_ROOT;
        }
        if ($this->changeCount === 0) {
            return PublishOutcome::NOTHING_TO_PUBLISH;
        }
        if (!$this->targetIsLive()) {
            return PublishOutcome::PUBLISH;
        }
        if ($this->confirmation === null || $this->confirmation === '') {
            return PublishOutcome::NEEDS_CONFIRMATION;
        }
        if ($this->confirmation !== $this->workspaceName) {
            return PublishOutcome::CONFIRMATION_MISMATCH;
        }

        return PublishOutcome::PUBLISH;
    }
}
