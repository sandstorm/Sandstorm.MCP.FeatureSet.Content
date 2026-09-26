<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ArraySchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * Sets node references, which no upstream tool covers: `content_update_content` writes
 * properties via SetNodeProperties and cannot express references at all.
 *
 * This tool started out in Sandstorm.MCP.FeatureSet.Media because that is where it was first
 * needed (wiring up migrated content), but it has no media aspect whatsoever - it is plain
 * content repository plumbing, which is why it lives here.
 */
class SetReferencesTool extends AbstractContentEditTool implements ToolConstructor
{
    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'set_references',
            description: 'Sets a named reference (e.g. "teamMemberReferences") on an existing content node to one or more target nodes. '
                . 'Use this instead of content_update_content for reference properties, which the property tools cannot set. '
                . 'The previously set references for that name are replaced (not merged); pass an empty target list to clear them.',
            inputSchema: self::nodeAddressSchema(
                'The node_address of the source content node whose references should be set',
                [
                    'reference_name' => (new StringSchema(
                        description: 'The reference name as declared in the NodeType (e.g. "teamMemberReferences")'
                    ))->required(),
                    'target_node_aggregate_ids' => (new ArraySchema(
                        description: 'Ordered list of target node aggregate ids (UUIDs) the reference should point to. Empty list clears the reference.',
                        items: new StringSchema()
                    ))->required(),
                ]
            ),
            annotations: new Annotations(title: 'Set Node References'),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $nodeAddress = $this->parseNodeAddress($input);
        $this->requireNonLiveWorkspace($nodeAddress, 'Setting node references');

        $referenceName = $this->parseReferenceName($input);
        $targetIds = $this->parseTargetNodeAggregateIds($input);

        $contentRepository = $this->getContentRepository($serverContext);

        $command = SetNodeReferences::create(
            workspaceName: $nodeAddress->workspaceName,
            sourceNodeAggregateId: $nodeAddress->aggregateId,
            // SetNodeReferences wants an ORIGIN dimension space point, unlike the move and
            // subtree tagging commands, which document a covered one.
            sourceOriginDimensionSpacePoint: OriginDimensionSpacePoint::fromDimensionSpacePoint($nodeAddress->dimensionSpacePoint),
            references: NodeReferencesToWrite::create(
                NodeReferencesForName::fromTargets(
                    ReferenceName::fromString($referenceName),
                    NodeAggregateIds::fromArray($targetIds)
                )
            )
        );

        $count = count($targetIds);

        return $this->handleCommand(
            $contentRepository,
            $command,
            "Reference '{$referenceName}' on node {$nodeAddress->aggregateId->value} set to {$count} target(s): "
            . implode(', ', $targetIds)
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseReferenceName(array $input): string
    {
        $referenceName = $input['reference_name'] ?? null;
        if (!\is_string($referenceName) || $referenceName === '') {
            throw new \InvalidArgumentException(
                'reference_name is required and must be the reference name declared in the NodeType, e.g. "teamMemberReferences".'
            );
        }

        return $referenceName;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<string>
     */
    private function parseTargetNodeAggregateIds(array $input): array
    {
        $targetIds = $input['target_node_aggregate_ids'] ?? [];
        if (!\is_array($targetIds)) {
            throw new \InvalidArgumentException(
                'target_node_aggregate_ids must be a list of node aggregate id strings; pass an empty list to clear the reference.'
            );
        }

        $parsed = [];
        foreach (array_values($targetIds) as $position => $targetId) {
            if (!\is_string($targetId)) {
                throw new \InvalidArgumentException(sprintf(
                    'target_node_aggregate_ids must contain only node aggregate id strings; entry %d is %s.',
                    $position,
                    get_debug_type($targetId)
                ));
            }

            $parsed[] = $targetId;
        }

        return $parsed;
    }
}
