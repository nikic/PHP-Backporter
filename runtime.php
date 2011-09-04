<?php

function _is_string($value) {
    return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
}

function _prepareConst($name) {
    $parts = explode('::', $name);
    $count = count($parts);
    if (1 === $count) {
        return str_replace('\\', '__', $name);
    } elseif (2 === $count) {
        return str_replace('\\', '__', $parts[0]) . '::' . $parts[1];
    } else {
        // invalid
        return $name;
    }
}

class _Closure
{
    protected $uses;

    public function __construct(array $uses) {
        $this->uses = $uses;
    }
}

// SPL Autoload overwrites
class _Closure_SPL
{
    private $callback;

    public function __construct($callback) {
        $this->callback = $callback;
    }

    public function call($class) {
        return call_user_func($this->callback, str_replace('__', '\\', $class));
    }
}

// Reflection overwrites
class _ReflectionClass extends ReflectionClass
{
    public function getName() {
        return str_replace('__', '\\', parent::getName());
    }

    public function getNamespaceName() {
        $name = $this->getName();
        if (false !== ($pos = strrpos($name, '\\'))) {
            return substr($name, 0, $pos);
        } else {
            return null;
        }
    }
}

class _ReflectionObject extends ReflectionObject
{
    public function getName() {
        return str_replace('__', '\\', parent::getName());
    }

    public function getNamespaceName() {
        $name = $this->getName();
        if (false !== ($pos = strrpos($name, '\\'))) {
            return substr($name, 0, $pos);
        } else {
            return null;
        }
    }
}