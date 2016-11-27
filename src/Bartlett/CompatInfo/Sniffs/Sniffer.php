<?php

namespace Bartlett\CompatInfo\Sniffs;

use RuntimeException;

/**
 * The sniff fabric
 */
class Sniffer
{
    private static $namespaces = ['Bartlett\\CompatInfo\\Sniffs\\PHP\\'];

    /**
     * Register a new sniff namespace.
     *
     * @param string $namespace
     */
    public static function registerNamespace($namespace)
    {
        self::$namespaces[] = $namespace;
    }

    /**
     * Create instances of the sniffs.
     *
     * @param string $name
     * @param array  $args
     */
    public static function __callStatic($name, $args)
    {
        foreach (self::$namespaces as $namespace) {
            $class = $namespace.ucfirst($name);

            if (!class_exists($class)) {
                // the sniff is not found in this $namespace
                continue;
            }
            switch (count($args)) {
                case 0:
                    return new $class();

                case 1:
                    return new $class($args[0]);

                default:
                    return (new \ReflectionClass($class))->newInstanceArgs($args);
            }
        }

        throw new RuntimeException("The sniff {$name} does not exits");
    }
}
