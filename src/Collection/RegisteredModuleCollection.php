<?php

namespace Hyperion\Loader\Collection;

class RegisteredModuleCollection
{
    private array $registeredModules = [];

    public function addModule(string $namespace) : void
    {
        if (!in_array($namespace, $this->registeredModules, true)) {
            $this->registeredModules[] = $namespace;
        }
    }

    public function getRegisteredModules() : array
    {
        return $this->registeredModules;
    }
}
