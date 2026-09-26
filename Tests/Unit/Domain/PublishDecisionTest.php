<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sandstorm\MCP\FeatureSet\Content\Domain\PublishDecision;
use Sandstorm\MCP\FeatureSet\Content\Domain\PublishOutcome;

/**
 * Covers the rules behind publish_workspace: publishing into a non-live base goes ahead,
 * publishing into live needs the workspace name repeated in confirm_publish_to_live.
 */
class PublishDecisionTest extends UnitTestCase
{
    private static function decide(string $workspace, ?string $base, int $changes = 3, ?string $confirmation = null): PublishOutcome
    {
        return (new PublishDecision($workspace, $base, $changes, $confirmation))->outcome();
    }

    /** @test */
    public function liveItselfIsRefused(): void
    {
        self::assertSame(PublishOutcome::REFUSE_LIVE, self::decide('live', null, confirmation: 'live'));
    }

    /** @test */
    public function otherRootWorkspaceIsRefused(): void
    {
        self::assertSame(PublishOutcome::REFUSE_ROOT, self::decide('some-root', null));
    }

    /** @test */
    public function workspaceWithoutChangesIsNotPublished(): void
    {
        self::assertSame(PublishOutcome::NOTHING_TO_PUBLISH, self::decide('review', 'live', 0, 'review'));
    }

    /** @test */
    public function nonLiveBasePublishesWithoutConfirmation(): void
    {
        self::assertSame(PublishOutcome::PUBLISH, self::decide('draft', 'review'));
    }

    /** @test */
    public function liveBaseWithoutConfirmationOnlyPreviews(): void
    {
        self::assertSame(PublishOutcome::NEEDS_CONFIRMATION, self::decide('review', 'live'));
    }

    /** @test */
    public function liveBaseWithEmptyConfirmationOnlyPreviews(): void
    {
        self::assertSame(PublishOutcome::NEEDS_CONFIRMATION, self::decide('review', 'live', confirmation: ''));
    }

    /** @test */
    public function liveBaseWithWrongConfirmationIsRefused(): void
    {
        // e.g. "yes" or "live" instead of the workspace name
        self::assertSame(PublishOutcome::CONFIRMATION_MISMATCH, self::decide('review', 'live', confirmation: 'yes'));
        self::assertSame(PublishOutcome::CONFIRMATION_MISMATCH, self::decide('review', 'live', confirmation: 'live'));
    }

    /** @test */
    public function liveBaseWithMatchingConfirmationPublishes(): void
    {
        self::assertSame(PublishOutcome::PUBLISH, self::decide('review', 'live', confirmation: 'review'));
    }
}
