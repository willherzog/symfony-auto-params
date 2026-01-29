<?php

namespace WHSymfony\DependencyInjection;

use Symfony\Component\Config\Definition\{ArrayNode,BaseNode,PrototypedArrayNode};
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use WHSymfony\Config\Definition\ConfigDefinitionAttributes;

/**
 * An abstract container extension which converts merged config values into container parameters automatically.
 *
 * @author Will Herzog <willherzog@gmail.com>
 */
abstract class AbstractApplicationExtension extends Extension
{
	private const PROTOTYPE_ARRAY_NODE = '__prototype_array_node__';

	private ContainerBuilder $containerBuilder;
	private array $configurationStructure = [];

	final public function __construct(
		private readonly int $maxParamDepth = 3,
		private readonly string $pathSeparator = BaseNode::DEFAULT_PATH_SEPARATOR
	) {}

	final public function load(array $configs, ContainerBuilder $containerBuilder): void
	{
		$this->containerBuilder = $containerBuilder;
		$configuration = $this->getConfiguration($configs, $containerBuilder);

		$configuration->getConfigTreeBuilder()->setPathSeparator($this->pathSeparator);

		$configTree = $configuration->getConfigTreeBuilder()->buildTree();
		$mergedConfig = $this->processConfiguration($configuration, $configs);

		$this->parseConfigTreeRecursive($configTree, $this->configurationStructure);

		$this->setContainerParamsRecursive($mergedConfig, $this->configurationStructure);
	}

	private function parseConfigTreeRecursive(NodeInterface $configTree, array &$parentStructure, int $paramDepth = 0): void
	{
		if( !$configTree instanceof ArrayNode ) {
			return;
		}

		if( $this->maxParamDepth > 0 ) {
			$paramDepth++;
		}

		foreach( $configTree->getChildren() as $node ) {
			/** @var ArrayNode $nodePrototype */
			if(
				$node instanceof ArrayNode
				&& $paramDepth <= $this->maxParamDepth
				&& (
					!$node instanceof PrototypedArrayNode
					|| (
						($nodePrototype = $node->getPrototype()) instanceof ArrayNode
						&& $nodePrototype->getAttribute(ConfigDefinitionAttributes::FORCE_CREATE_AUTO_PARAM)
					)
				)
			) {
				$nodeStructure = [];

				if( $node instanceof PrototypedArrayNode ) {
					$this->parseConfigTreeRecursive($nodePrototype, $nodeStructure, $paramDepth);

					$parentStructure[$node->getName()] = [self::PROTOTYPE_ARRAY_NODE => $nodeStructure];
				} else {
					$this->parseConfigTreeRecursive($node, $nodeStructure, $paramDepth);

					$parentStructure[$node->getName()] = $nodeStructure;
				}
			} else {
				$parentStructure[$node->getName()] = $node->getPath();
			}
		}
	}

	private function setContainerParamsRecursive(array $configValues, array $configStructure, array $prototypeKeys = []): void
	{
		foreach( $configValues as $key => $value ) {
			if( key_exists($key, $configStructure) ) {
				$thisConfigStructure = $configStructure[$key];
				$prototypeKeysMerge = [];
			} elseif( key_exists(self::PROTOTYPE_ARRAY_NODE, $configStructure) ) {
				$thisConfigStructure = $configStructure[self::PROTOTYPE_ARRAY_NODE];
				$prototypeKeysMerge = [$key];
			} else {
				throw new \OutOfBoundsException(sprintf('No matching definition found for node "%s" of configuration tree.', $key));
			}

			if( is_array($thisConfigStructure) ) {
				if( is_array($value) ) {
					$this->setContainerParamsRecursive($value, $thisConfigStructure, array_merge($prototypeKeys, $prototypeKeysMerge));
				} else {
					throw new \UnexpectedValueException(sprintf('Found non-array value for array node "%s" of configuration tree.', $key));
				}
			} else {
				$finalParamName = $thisConfigStructure;

				if( $prototypeKeys !== [] ) {
					if( !isset($numPrototypeKeys) ) {
						$numPrototypeKeys = count($prototypeKeys);
					}

					$search = $this->pathSeparator . $this->pathSeparator;
					$numSearchOccurrences = substr_count($finalParamName, $search);

					if( $numSearchOccurrences !== $numPrototypeKeys ) {
						throw new \RangeException(sprintf('Prototype discrepancy (%d vs. %d) found in path for node "%s" of configuration tree.', $numSearchOccurrences, $numPrototypeKeys, $key));
					}

					$prototypeKeysClone = $prototypeKeys;

					do {
						$replacement = $this->pathSeparator . array_shift($prototypeKeysClone) . $this->pathSeparator;
						$finalParamName = substr_replace($finalParamName, $replacement, strpos($finalParamName, $search), strlen($search));
					} while( str_contains($finalParamName, $search) && $prototypeKeysClone !== [] );
				}

				$this->containerBuilder->setParameter($finalParamName, $value);
			}
		}
	}
}
