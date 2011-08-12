<?php

class PHPBackporter_Converter_Namespace extends PHPParser_NodeVisitorAbstract
{
    /**
     * @var null|PHPParser_Node_Name Current namespace
     */
    protected $namespace;

    /**
     * @var array Currently used namespaces and classes
     */
    protected $aliases;

    public function beforeTraverse(&$node) {
        $this->namespace = null;
        $this->aliases   = array();
    }

    public function enterNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Stmt_Namespace) {
            $this->namespace = $node->name;
        } elseif ($node instanceof PHPParser_Node_Stmt_UseUse) {
            if (isset($this->aliases[$node->alias])) {
                throw new PHPParser_Error(sprintf(
                    'Cannot use %s as %s because the name is already in use',
                    $node->name, $node->alias
                ));
            }

            $this->aliases[$node->alias] = $node->name;
        }
    }

    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Stmt_Class
            || $node instanceof PHPParser_Node_Stmt_Interface
            || $node instanceof PHPParser_Node_Stmt_Func
            || $node instanceof PHPParser_Node_Stmt_ConstConst
        ) {
            $this->rewriteDefinition($node->name);

            // rewrite lookups in extends and implements
            if ($node instanceof PHPParser_Node_Stmt_Class) {
                $this->rewriteLookup($node->extends, T_CLASS);

                foreach ($node->implements as $interface) {
                    $this->rewriteLookup($interface, T_CLASS);
                }
            }

            if ($node instanceof PHPParser_Node_Stmt_Interface) {
                foreach ($node->extends as $interface) {
                    $this->rewriteLookup($interface, T_CLASS);
                }
            }
        // rewrite lookups
        } elseif ($node instanceof PHPParser_Node_Expr_StaticCall
            || $node instanceof PHPParser_Node_Expr_StaticPropertyFetch
            || $node instanceof PHPParser_Node_Expr_ClassConstFetch
            || $node instanceof PHPParser_Node_Expr_New
            || $node instanceof PHPParser_Node_Expr_Instanceof
        ) {
            $this->rewriteLookup($node->class, T_CLASS);
        } elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
            $this->rewriteLookup($node->name, T_FUNCTION);

            if ('define' == $node->name) {
                // missing first argument
                if (count($node->args) < 2) {
                    throw new PHPParser_Error('define() is expecting at least two arguments', $node->getLine());
                }

                // dynamic definition TODO
                if (!$node->args[0]->value instanceof PHPParser_Node_Scalar_String) {
                    return;
                }

                $this->rewriteDefinition($node->args[0]->value->value);
            }
        } elseif ($node instanceof PHPParser_Node_Expr_ConstFetch) {
            $this->rewriteLookup($node->name, T_CONST);
        } elseif ($node instanceof PHPParser_Node_Stmt_FuncParam) {
            $this->rewriteLookup($node->type, T_CLASS);
        // rewrite __NAMESPACE__
        } elseif ($node instanceof PHPParser_Node_Scalar_NSConst) {
            $node = new PHPParser_Node_Scalar_String(
                null !== $this->namespace ? $this->namespace->toString('_') : ''
            );
        // remove use statements
        } elseif ($node instanceof PHPParser_Node_Stmt_Use) {
            return false;
        // remove namespace statements
        } elseif ($node instanceof PHPParser_Node_Stmt_Namespace) {
            return $node->stmts;
        }
    }

    protected function rewriteDefinition(&$name) {
        if (null !== $this->namespace) {
            $name = $this->namespace->toString('_') . '_' . $name;
        }
    }

    protected function rewriteLookup(&$name, $type) {
        // requires dynamic resolution TODO
        if (!$name instanceof PHPParser_Node_Name) {
            return;
        }

        // resolve relative namespaces
        if ($name->isRelative()) {
            if (null !== $this->namespace) {
                $name->prepend($this->namespace);
            }

            $name->type = PHPParser_Node_Name::FULLY_QUALIFIED;
        } elseif ($name->isQualified()) {
            // if the first part is a known alias replace it
            if (isset($this->aliases[$name->getFirst()])) {
                $name->setFirst($this->aliases[$name->getFirst()]);
            // otherwise prepend current namespace
            } elseif (null !== $this->namespace) {
                $name->prepend($this->namespace);
            }

            $name->type = PHPParser_Node_Name::FULLY_QUALIFIED;
        // for unqualified names alias resolution is only done for classes
        } elseif (T_CLASS == $type && $name->isUnqualified()
                  && isset($this->aliases[$name->getFirst()])
        ) {
            $name->set($this->aliases[$name->getFirst()]);
            $name->type = PHPParser_Node_Name::FULLY_QUALIFIED;
        }

        // for fully qualified names or names in the global namespace no further actions required
        if (!$name->isFullyQualified() && null !== $this->namespace) {
            // for classes prepend the current namespace
            // for functions and constants prepend the current namespace only if they are not
            // defined globally (yes, global functions and constants can be redefined in a namespace.
            // I am doing this simplification as a proper resolution would require additional runtime
            // code.)
            if (T_CLASS == $type
                || (T_FUNCTION == $type && !function_exists($name))
                || (T_CONST == $type && !defined($name))
            ) {
                $name->prepend($this->namespace);
            }
        }

        // finally just replace the namespace separators with underscores
        $name->set($name->toString('_'));
        $name->type = PHPParser_Node_Name::NORMAL;
    }
}