<?php

namespace Hyperion\Loader;

use Composer\Autoload\ClassLoader;
use Hyperion\Loader\Service\ContainerEngine;
use Hyperion\Loader\Collection\AutoloadedNamespaceCollection;
use ReflectionParameter;
use WP_CLI;

class HyperionLoader
{
    public const REGISTER_AUTOLOADED_NAMESPACE = 'hyperion_loader_autoloaded_namespace';
    public const HYPERION_CONTAINER_READY = 'hyperion_loader_container_ready';
    private const DEPENDENCY_CACHE_KEY = 'hyperion_loader_dependency_cache_key';
    private ClassLoader $loader;

    public function init()
    {
        $autoloadedNamespaces = new AutoloadedNamespaceCollection();
        if (defined('WP_CLI')) {
            $this->loader = require($_SERVER['DOCUMENT_ROOT'].'/../../vendor/autoload.php');
        } else {
            $this->loader = require($_SERVER['DOCUMENT_ROOT'].'/../vendor/autoload.php');
        }

        do_action(self::REGISTER_AUTOLOADED_NAMESPACE, $autoloadedNamespaces);

        if (false === apcu_exists(self::DEPENDENCY_CACHE_KEY)) {
            $this->computeDependencies($autoloadedNamespaces);
        }

        $containerEngine = new ContainerEngine();
        $containerEngine->setContainer(apcu_fetch(self::DEPENDENCY_CACHE_KEY));

        do_action(self::HYPERION_CONTAINER_READY, $containerEngine);
    }

    /**
     * Récupère toutes les classes qui sont gérées dans ce namespace
     */
    private function getAllInstanciableClassesFromDir(string $namespace) : array
    {
        $classMap = $this->loader->getClassMap();
        $results = array_filter($classMap, static function ($key) use ($namespace) {
            $key = substr($key, 0, strlen($key) - (int) strpos(strrev($key), "\\") - 1);
            return $key === $namespace;
        }, ARRAY_FILTER_USE_KEY);

        return array_keys($results);
    }

    /**
     * @param string $classNamespace
     * @throws \ReflectionException
     * @return ReflectionParameter[]
     */
    private function getConstructorServices(string $classNamespace) : array
    {
        $classReflection = new \ReflectionClass($classNamespace);
        $constructor = $classReflection->getConstructor();

        if (is_null($constructor)) {
            return [];
        }

        return $constructor->getParameters();
    }

    /**
     * @param AutoloadedNamespaceCollection $registeredModules
     * @throws \ReflectionException
     */
    private function computeDependencies(AutoloadedNamespaceCollection $autoloadedNamespaceCollection)
    {
        $dependencies = [];
        foreach ($autoloadedNamespaceCollection->getNamespaces() as $namespaces) {
            $classesInNamespace = $this->getAllInstanciableClassesFromDir($namespaces);
            foreach ($classesInNamespace as $classNamespace) {
                $this->recursiveConstructorServicesDependencies($classNamespace, $dependencies);
            }
        }

        apcu_add(self::DEPENDENCY_CACHE_KEY, $dependencies);
    }

    /**
     * @param string $classNamespace
     * @param array $dependencies
     * @throws \ReflectionException
     */
    private function recursiveConstructorServicesDependencies(string $classNamespace, array &$dependencies)
    {
        $constructorParameters = $this->getConstructorServices($classNamespace);
        if (empty($constructorParameters)) {
            $dependencies[$classNamespace] = [];
            return;
        }

        foreach ($this->getConstructorServices($classNamespace) as $param) {
            $classParam = $param->getType();

            // Si ce n'est pas une classe on skip.
            if (is_null($classParam)) {
                continue;
            }

            $classParamName = $classParam->getName();
            $dependencies[$classNamespace][$classParamName] = [];
            $this->recursiveConstructorServicesDependencies($classParamName, $dependencies[$classNamespace]);
        }
    }
}
