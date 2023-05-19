<?php

namespace WHSymfony\DependencyInjection;

use Symfony\Component\Config\Definition\{ArrayNode,BaseNode,PrototypedArrayNode};
use Symfony\Component\Config\Definition\{ConfigurationInterface,NodeInterface};
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use WHPHP\Util\ArrayUtil;

/**
 * An abstract container extension which converts merged config values into container parameters automatically.
 *
 * @author Will Herzog <willherzog@gmail.com>
 */
abstract class AbstractApplicationExtension extends Extension
{
	private ContainerBuilder $containerBuilder;
	private array $configurationStructure = [];

	final public function __construct(
		private readonly int $maxParamDepth = 3, // Prevent parameters being created beyond this depth
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
		if( !get_class($configTree) === ArrayNode::class ) {
			return;
		}

		if( $this->maxParamDepth > 0 ) {
			$paramDepth++;
		}

		foreach( $configTree->getChildren() as $node ) {
			if( ($node instanceof ArrayNode) && !($node instanceof PrototypedArrayNode) && $paramDepth <= $this->maxParamDepth ) {
				$childStructure = [];

				$this->parseConfigTreeRecursive($node, $childStructure, $paramDepth);

				$parentStructure[$node->getName()] = $childStructure;
			} else {
				$parentStructure[$node->getName()] = $node->getPath();
			}
		}
	}

	private function setContainerParamsRecursive(array $configValues, array $configStructure): void
	{
		$paramNamePrefix = $this->getAlias() . $this->pathSeparator;

		foreach( $configValues as $key => $value ) {
			if( is_array($configStructure[$key]) ) {
				if( is_array($value) ) {
					$this->setContainerParamsRecursive($value, $configStructure[$key]);
				} else {
					throw new \UnexpectedValueException(sprintf('Found non-array value for array node "%s" of configuration tree.', $key));
				}
			} else {
				$this->containerBuilder->setParameter($paramNamePrefix . $configStructure[$key], $value);
			}
		}
	}
}
