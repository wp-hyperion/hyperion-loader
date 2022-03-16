<?php

namespace Hyperion\Loader\Collection;

class AutoloadedNamespaceCollection
{
    private array $autoloadNamespaces = [];

    public function addAutoloadNamespace(string $namespace) : void
    {
        if (!in_array($namespace, $this->autoloadNamespaces, true)) {
            $this->autoloadNamespaces[] = $namespace;
        }
    }

    public function getNamespaces() : array
    {
        return $this->autoloadNamespaces;
    }
}
