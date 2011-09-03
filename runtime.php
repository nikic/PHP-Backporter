<?php

function _is_string($value) {
    return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
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
        return call_user_func($this->callback, strtr($class, '_', '\\'));
    }
}

// Reflection overwrites
class _ReflectionClass extends ReflectionClass
{
    public function getNamespaceName() {
        $name = $this->getName();
        if (false !== ($pos = strrpos($name, '_'))) {
            return strtr(substr($name, 0, $pos), '_', '\\');
        } else {
            return null;
        }
    }
}

class _ReflectionObject extends ReflectionObject
{
    public function getNamespaceName() {
        $name = $this->getName();
        if (false !== ($pos = strrpos($name, '_'))) {
            return strtr(substr($name, 0, $pos), '_', '\\');
        } else {
            return null;
        }
    }
}