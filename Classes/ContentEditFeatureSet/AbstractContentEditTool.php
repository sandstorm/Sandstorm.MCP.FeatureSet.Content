<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Content\ContentEditFeatureSet;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\AbstractSchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * Shared plumbing for every write tool in this feature set.
 *
 * Everything here is easy to get subtly wrong and is therefore written down once:
 *
 *  - the subgraph is built with VisibilityConstraints::createEmpty(), NOT
 *    withoutRestrictions() and not default(): both of the latter still exclude tagged nodes,
 *    so an already hidden node would look as if it did not exist at all, and a hidden sibling
 *    would silently drop out of any order calculation. The content repository's own handlers
 *    use createEmpty() throughout for the same reason (NodeMove::handleMoveNodeAggregate).
 *  - none of these tools may touch the live workspace; changes have to be reviewable and
 *    publishable, hence {@see requireNonLiveWorkspace()}.
 *  - content repository constraint violations have to be caught locally, see the docblock of
 *    {@see handleCommand()}.
 *
 * Note for anyone adding a tool here: subclasses carry #[Flow\Inject] and are therefore
 * Flow-proxied. A proxied class can never be instantiated with named arguments, because the
 * generated proxy constructor declares no parameters and reads func_get_args() - see
 * docs/2026_09_16_MCP_Server_500_Named_Arguments_Flow_Proxy.md. Calling
 * parent::__construct(name: ..., ...) is fine; Tool itself is abstract and not proxied.
 */
abstract class AbstractContentEditTool extends Tool
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * The node_address object schema, identical to the one every other CR tool in this
     * project declares. Extra tool specific properties can be merged in by the caller.
     *
     * @param array<string,AbstractSchema> $additionalProperties
     */
    final protected static function nodeAddressSchema(string $description, array $additionalProperties = []): ObjectSchema
    {
        return new ObjectSchema(properties: array_merge([
            'node_address' => (new ObjectSchema(
                description: $description,
                properties: [
                    'contentRepositoryId' => (new StringSchema())->required(),
                    'workspaceName' => (new StringSchema())->required(),
                    'dimensionSpacePoint' => (new ObjectSchema())->required(),
                    'aggregateId' => (new StringSchema())->required(),
                ]
            ))->required(),
        ], $additionalProperties));
    }

    /**
     * @param array<string,mixed> $input
     */
    final protected function parseNodeAddress(array $input): NodeAddress
    {
        $nodeAddress = $input['node_address'] ?? [];
        if (!\is_array($nodeAddress)) {
            throw new \InvalidArgumentException('node_address must be an object with contentRepositoryId, workspaceName, dimensionSpacePoint and aggregateId.');
        }
        /** @var array<string,mixed> $nodeAddress */

        return NodeAddress::fromArray($nodeAddress);
    }

    /**
     * @param string $action what the tool is about to do, e.g. "Changing node visibility" -
     *                       it is the subject of the refusal sentence
     */
    final protected function requireNonLiveWorkspace(NodeAddress $nodeAddress, string $action): void
    {
        if ($nodeAddress->workspaceName->isLive()) {
            throw new \InvalidArgumentException(
                $action . ' on the Live workspace is disabled. '
                . 'Use a non-live workspace and publish the change after review.'
            );
        }
    }

    final protected function getContentRepository(ServerContext $serverContext): ContentRepository
    {
        $httpRequest = $serverContext->request->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest($httpRequest)->contentRepositoryId;

        return $this->contentRepositoryRegistry->get($contentRepositoryId);
    }

    final protected function getSubgraph(ContentRepository $contentRepository, NodeAddress $nodeAddress): ContentSubgraphInterface
    {
        return $contentRepository
            ->getContentGraph($nodeAddress->workspaceName)
            ->getSubgraph($nodeAddress->dimensionSpacePoint, VisibilityConstraints::createEmpty());
    }

    final protected function requireNode(ContentSubgraphInterface $subgraph, NodeAddress $nodeAddress): Node
    {
        $node = $subgraph->findNodeById($nodeAddress->aggregateId);
        if ($node === null) {
            throw new \InvalidArgumentException(
                "Could not find node {$nodeAddress->aggregateId->value} in workspace {$nodeAddress->workspaceName->value}."
            );
        }

        return $node;
    }

    final protected function requireNodeAggregate(ContentRepository $contentRepository, NodeAddress $nodeAddress): NodeAggregate
    {
        $nodeAggregate = $contentRepository
            ->getContentGraph($nodeAddress->workspaceName)
            ->findNodeAggregateById($nodeAddress->aggregateId);

        if ($nodeAggregate === null) {
            throw new \InvalidArgumentException(
                "Could not find node aggregate {$nodeAddress->aggregateId->value} in workspace {$nodeAddress->workspaceName->value}."
            );
        }

        return $nodeAggregate;
    }

    /**
     * The node's ORIGIN dimension space point.
     *
     * This is what the subtree tagging commands want, mirroring what the Neos backend does
     * when an editor ticks the hide checkbox (Neos.Neos.Ui Changes/Property.php
     * ::handleHiddenPropertyChange). Do NOT reach for this in tools whose command documents a
     * *covered* dimension space point - MoveNodeAggregate is the notable one; there the
     * dimension space point from the NodeAddress is the correct input.
     */
    final protected function targetDimensionSpacePoint(Node $node): DimensionSpacePoint
    {
        return $node->originDimensionSpacePoint->toDimensionSpacePoint();
    }

    /**
     * Runs the command and turns Content Repository constraint violations into a readable
     * tool result.
     *
     * This has to catch \DomainException rather than a CR base exception: every relevant CR
     * exception (SubtreeIsAlreadyTagged, SubtreeIsNotTagged, NodeAggregateCurrentlyDoesNotExist,
     * NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint, NodeAggregateIsTethered,
     * NodeAggregateIsDescendant, NodeAggregateIsNoSibling, NodeConstraintException,
     * NodeNameIsAlreadyCovered, ContentStreamIsClosed) extends \DomainException directly, and
     * so does this project's own UriPathCollisionDetected, which the command hook in
     * Sandstorm.NodeTypes.Folder throws for moves into a new parent. Note that
     * AbstractFeatureSet::catchCRExceptions() is no substitute: in sjs/flow-mcp v1.0.1 it catches
     * Neos\ContentRepository\Core\SharedModel\Exception, which is a namespace and not a class, so
     * it never matches; later versions catch every \Exception, including programming errors.
     */
    final protected function handleCommand(ContentRepository $contentRepository, CommandInterface $command, string $successMessage): Content
    {
        try {
            $contentRepository->handle($command);
        // @phpstan-ignore catch.neverThrown (the CR exceptions above are undeclared in @throws but demonstrably thrown - see the docblock)
        } catch (\DomainException $e) {
            return Content::text(
                'The content repository refused the change: ' . $e->getMessage()
            );
        }

        return Content::text($successMessage);
    }
}
