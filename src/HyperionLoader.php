<?php

namespace Hyperion\Loader;

use Composer\Autoload\ClassLoader;
use Hyperion\Loader\Service\ContainerEngine;
use Hyperion\Loader\Collection\RegisteredModuleCollection;
use ReflectionParameter;

class HyperionLoader
{
    public const REGISTER_HYPERION_MODULE = 'hyperion_loader_register_hyperion_module';
    public const HYPERION_CONTAINER_READY = 'hyperion_loader_container_ready';
    private const DEPENDENCY_CACHE_KEY = 'hyperion_loader_dependency_cache_key';
    private ClassLoader $loader;

    public function init()
    {
        $registeredModules = new RegisteredModuleCollection();
        $this->loader = require($_SERVER['DOCUMENT_ROOT'].'/../vendor/autoload.php');

        do_action(self::REGISTER_HYPERION_MODULE, $registeredModules);

        if (false === apcu_exists(self::DEPENDENCY_CACHE_KEY)) {
            $this->computeDependencies($registeredModules);
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
     * @param RegisteredModuleCollection $registeredModules
     * @throws \ReflectionException
     * @todo : Attention boucle trop importante ! ==> monter en compétence sur blackfire
     */
    private function computeDependencies(RegisteredModuleCollection $registeredModules)
    {
        $dependencies = [];
        foreach ($registeredModules->getRegisteredModules() as $registeredModuleNamespace) {
            $classesInNamespace = $this->getAllInstanciableClassesFromDir($registeredModuleNamespace);
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
