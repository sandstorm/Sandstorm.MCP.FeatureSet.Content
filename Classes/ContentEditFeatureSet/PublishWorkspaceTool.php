<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
use Sandstorm\MCP\FeatureSet\Content\Domain\PublishDecision;
use Sandstorm\MCP\FeatureSet\Content\Domain\PublishOutcome;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * Publishes a workspace to its base workspace, but asks before that base is live.
 *
 * Replaces upstream's workspace_publish_workspace (sjs/neos-mcp-feature-set-neos), which
 * publishes any workspace straight into its base - live included - without a second look and
 * without a destructiveHint. Disable that one via SJS.Flow.MCP.server.mcp.disabledTools.
 * The rules live in {@see PublishDecision}.
 */
class PublishWorkspaceTool extends AbstractContentEditTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'publish_workspace',
            description: 'Publishes ALL pending changes of a workspace to its base workspace. '
                . 'If the base workspace is LIVE, the first call publishes nothing and returns a preview '
                . '(number of changes). Show that preview to the user and ask for explicit confirmation. '
                . 'Only after the user confirmed, call again with confirm_publish_to_live set to the workspace name. '
                . 'Never confirm on your own. If the base workspace is not live, the workspace is published directly. '
                . 'Be aware of eventual consistency: let some seconds pass before reading again.',
            inputSchema: new ObjectSchema(properties: [
                'workspace_name' => (new StringSchema(description: 'Technical name of the workspace to publish'))->required(),
                'confirm_publish_to_live' => new StringSchema(
                    description: 'Only when the base workspace is live and the USER explicitly confirmed: the workspace name again.'
                ),
            ]),
            annotations: new Annotations(
                title: 'Publish Workspace (asks before live)',
                destructiveHint: true
            ),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $workspaceName = WorkspaceName::fromString((string)$input['workspace_name']);
        $confirmation = isset($input['confirm_publish_to_live']) ? (string)$input['confirm_publish_to_live'] : null;

        $contentRepository = $this->getContentRepository($serverContext);
        $workspace = $contentRepository->findWorkspaceByName($workspaceName);
        if ($workspace === null) {
            throw new \InvalidArgumentException("Workspace {$workspaceName->value} does not exist.");
        }

        $decision = new PublishDecision(
            workspaceName: $workspaceName->value,
            baseWorkspaceName: $workspace->baseWorkspaceName?->value,
            changeCount: $contentRepository->projectionState(ChangeFinder::class)
                ->countByContentStreamId($workspace->currentContentStreamId),
            confirmation: $confirmation,
        );

        $target = $decision->baseWorkspaceName ?? '';
        $summary = "{$decision->changeCount} change(s) from workspace {$decision->workspaceName} to {$target}";

        return match ($decision->outcome()) {
            PublishOutcome::REFUSE_LIVE => Content::text('The live workspace cannot be published - it has no base workspace.'),
            PublishOutcome::REFUSE_ROOT => Content::text("Workspace {$decision->workspaceName} is a root workspace without a base workspace; nothing to publish to."),
            PublishOutcome::NOTHING_TO_PUBLISH => Content::text("Workspace {$decision->workspaceName} has no pending changes; nothing published."),
            PublishOutcome::NEEDS_CONFIRMATION => Content::text(
                "NOT PUBLISHED. This would publish {$summary} (LIVE - visible to all visitors). "
                . 'Ask the user to confirm explicitly. Only if they do, call this tool again with '
                . "confirm_publish_to_live: \"{$decision->workspaceName}\"."
            ),
            PublishOutcome::CONFIRMATION_MISMATCH => Content::text(
                "NOT PUBLISHED. confirm_publish_to_live must be exactly \"{$decision->workspaceName}\"."
            ),
            PublishOutcome::PUBLISH => $this->publish($contentRepository, $workspaceName, $summary),
        };
    }

    private function publish(ContentRepository $contentRepository, WorkspaceName $workspaceName, string $summary): Content
    {
        try {
            $contentRepository->handle(PublishWorkspace::create($workspaceName));
        // Rebase conflicts (WorkspaceRebaseFailed) are \RuntimeExceptions, constraint violations
        // \DomainExceptions - see AbstractContentEditTool::handleCommand().
        } catch (\RuntimeException | \DomainException $e) {
            return Content::text('The content repository refused to publish: ' . $e->getMessage());
        }

        return Content::text("Published {$summary}.");
    }
}
