<?php

class PHPBackporter_ArgHandler
{
    protected $functions;
    protected $handlers;

    public function __construct(array $functions = array(), array $handlers = array()) {
        $this->functions = array();
        $this->actions   = array();

        $this->addFunctions($functions);
        $this->addHandlers($handlers);
    }

    public function addFunctions(array $functions) {
        foreach ($functions as $data) {
            $this->addFunction($data[0], $data[1], $data[2]);
        }
    }

    public function addHandlers(array $handlers) {
        foreach ($handlers as $action => $handler) {
            $this->addHandler($action, $handler);
        }
    }

    public function addFunction($name, $args, $action) {
        if (!isset($this->functions[$name])) {
            $this->functions[$name] = array();
        }

        foreach ($args as $arg) {
            if (isset($this->functions[$name][$arg])) {
                throw new InvalidArgumentException(sprintf(
                    'Argument %d of function "%s" already has action "%s" assigned.'
                    . ' Cannot assign it action "%s"',
                    $arg, $name, $this->functions[$name][$arg], $action
                ));
            }

            $this->functions[$name][$arg] = $action;
        }
    }

    public function addHandler($action, $handler) {
        if (!is_callable($handler)) {
            throw new InvalidArgumentException(
                sprintf('Handler for action "%s" not callable', $action)
            );
        }

        if (isset($this->handlers[$action])) {
            throw new InvalidArgumentException(
                sprintf('Handler for action "%s" already registered', $action)
            );
        }

        $this->handlers[$action] = $handler;
    }

    public function handle(PHPParser_Node_Expr_FuncCall &$node) {
        $name = (string) $node->name;
        if (isset($this->functions[$name])) {
            foreach ($this->functions[$name] as $arg => $action) {
                if ($arg < 0) {
                    $arg += count($node->args);
                }

                if (isset($node->args[$arg])) {
                    $node->args[$arg]->value =
                        call_user_func($this->handlers[$action], $node->args[$arg]->value);
                }
            }
        }
    }
}