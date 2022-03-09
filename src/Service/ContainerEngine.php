<?php

namespace Hyperion\Loader\Service;

use Digilist\DependencyGraph\DependencyGraph;
use Digilist\DependencyGraph\DependencyNode;
use League\Container\Container;

class ContainerEngine
{
    private const CONTAINER_CACHE_KEY = 'hyperion_loader_container';

    private Container $container;
    private DependencyGraph $graph;
    /** @var DependencyNode[] */
    private array $dependencyNodes;

    public function __construct()
    {
        $this->container = new Container();
        $this->graph = new DependencyGraph();
    }

    /**
     * Permet de populer le container avec un module ou
     * l'ensemble des classes définies dans le namespace de l'autoloadedComponent.
     *
     * @param array $classMap
     * @throws \Digilist\DependencyGraph\CircularDependencyException
     * @return void
     */
    public function setContainer(array $classMap)
    {
        if (false === apcu_exists(self::CONTAINER_CACHE_KEY)) {
            $this->createDependencyNodes($classMap);
            $this->addDependencies($classMap);

            /** @var string $dependencyNode */
            foreach ($this->graph->resolve() as $dependencyNode) {
                $this->addContainerDependency($dependencyNode, $classMap);
            }
            apcu_add(self::CONTAINER_CACHE_KEY, $this->container);
            unset($this->dependencyNodes, $this->graph);
        }

        $this->container = apcu_fetch(self::CONTAINER_CACHE_KEY);
    }

    public function getContainer() : Container
    {
        return $this->container;
    }

    private function addContainerDependency(string $namespace, array $classMap) : void
    {
        if (count($classMap[$namespace])) {
            foreach ($classMap[$namespace] as $depNamespace => $dependencies) {
                $this->addContainerDependency($depNamespace, $classMap[$namespace]);
                $this->container->add($namespace)->addArguments(array_keys($classMap[$namespace]));
            }
            return;
        }
        $this->container->add($namespace);
    }

    private function addDependencies(array $classMap, string $parentNamespace = null) : void
    {
        foreach ($classMap as $namespace => $dependencies) {
            if (count($dependencies)) {
                $this->addDependencies($dependencies, $namespace);
                continue;
            }

            if ($parentNamespace) {
                $this->graph->addDependency($this->dependencyNodes[$parentNamespace], $this->dependencyNodes[$namespace]);
            }
        }
    }

    private function createDependencyNodes(array $classMap) : void
    {
        foreach ($classMap as $namespace => $dependencies) {
            if (count($dependencies)) {
                $this->createDependencyNodes($dependencies);
            }
            $node = new DependencyNode($namespace);
            $this->graph->addNode($node);
            $this->dependencyNodes[$namespace] = $node;
        }
    }
}
