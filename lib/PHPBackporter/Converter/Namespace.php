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

    /**
     * @var array Hash of internal functions / constants
     */
    protected $internals;

    /**
     * @var PHPBackporter_ArgHandler Function argument handler
     */
    protected $argHandler;

    protected static $classReturnFuncs = array(
        'get_class'        => true,
        'get_parent_class' => true,
    );

    protected static $specialClasses = array(
        'ReflectionClass'  => '_ReflectionClass',
        'ReflectionObject' => '_ReflectionObject',
    );

    public function __construct() {
        $functionDataParser = new PHPBackporter_FunctionDataParser;
        $this->argHandler = new PHPBackporter_ArgHandler(
            $functionDataParser->parse(file_get_contents('./function.data')),
            array(
                'define'              => array($this, 'handleDefineArg'),
                'splAutoloadRegister' => array($this, 'handleSplAutoloadRegisterArg'),
                'className'           => array($this, 'handleClassNameArg'),
                'unsafeClassName'     => array($this, 'handleUnsafeClassNameArg'),
                'const'               => array($this, 'handleConstArg')
            )
        );

        $functions = get_defined_functions();
        $functions = $functions['internal'];

        $consts = get_defined_constants(true);
        unset($consts['user']);
        $consts = array_keys(call_user_func_array('array_merge', $consts));

        $this->internals = array(
            T_FUNCTION => array_change_key_case(array_fill_keys($functions, true), CASE_LOWER),
            T_CONST    => array_change_key_case(array_fill_keys($consts,    true), CASE_LOWER),
        );
    }

    public function beforeTraverse(&$node) {
        $this->namespace = null;
        $this->aliases   = array();
    }

    public function enterNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Stmt_Namespace) {
            $this->namespace = $node->name;
            $this->aliases   = array();
        } elseif ($node instanceof PHPParser_Node_Stmt_UseUse) {
            if (isset($this->aliases[$node->alias])) {
                throw new PHPParser_Error(
                    sprintf(
                        'Cannot use %s as %s because the name is already in use',
                        $node->name, $node->alias
                    ),
                    $node->getLine()
                );
            }

            $this->aliases[$node->alias] = $node->name;
        }
    }

    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Stmt_Class
            || $node instanceof PHPParser_Node_Stmt_Interface
            || $node instanceof PHPParser_Node_Stmt_Func
        ) {
            $this->rewriteDefinition($node->name);

            if ($node instanceof PHPParser_Node_Stmt_Class) {
                if (null !== $node->extends) {
                    $this->rewriteStaticClassLookup($node->extends);
                }

                foreach ($node->implements as $interface) {
                    $this->rewriteStaticClassLookup($interface);
                }
            }

            if ($node instanceof PHPParser_Node_Stmt_Interface) {
                foreach ($node->extends as $interface) {
                    $this->rewriteStaticClassLookup($interface);
                }
            }
        } elseif ($node instanceof PHPParser_Node_Expr_StaticCall
                  || $node instanceof PHPParser_Node_Expr_StaticPropertyFetch
                  || $node instanceof PHPParser_Node_Expr_ClassConstFetch
                  || $node instanceof PHPParser_Node_Expr_New
                  || $node instanceof PHPParser_Node_Expr_Instanceof
        ) {
            $this->rewriteLookup($node->class, T_CLASS);

            // rewrite class argument to ReflectionClass
            if ($node instanceof PHPParser_Node_Expr_New
                && $node->class instanceof PHPParser_Node_Name
                && '_ReflectionClass' == $node->class
                && isset($node->args[0])
            ) {
                $node->args[0]->value = $this->createFromNamespacedNode($node->args[0]->value);
            }
        } elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
            $this->rewriteLookup($node->name, T_FUNCTION);
            $this->rewriteSpecialFunctions($node);
        } elseif ($node instanceof PHPParser_Node_Expr_ConstFetch) {
            $this->rewriteLookup($node->name, T_CONST);
        } elseif ($node instanceof PHPParser_Node_Param
                  && $node->type instanceof PHPParser_Node_Name
        ) {
            $this->rewriteStaticClassLookup($node->type);
        // rewrite __NAMESPACE__
        } elseif ($node instanceof PHPParser_Node_Scalar_NSConst) {
            $node = new PHPParser_Node_Scalar_String(
                null !== $this->namespace ? $this->namespace->toString('__') : ''
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
            $name = $this->namespace->toString('__') . '__' . $name;
        }
    }

    protected function rewriteLookup(&$name, $type) {
        if (!$name instanceof PHPParser_Node_Name) {
            $name = $this->createInlineExpr($this->createFromNamespacedNode($name, false));
        } elseif (T_CLASS === $type) {
            $this->rewriteStaticClassLookup($name);
        } else {
            $this->rewriteStaticOtherLookup($name, $type);
        }
    }

    protected function rewriteStaticClassLookup(PHPParser_Node_Name &$name) {
        // don't try to resolve special class names
        if (in_array((string) $name, array('self', 'parent', 'static'))) {
            return;
        }

        // leave the fully qualified ones alone
        if (!$name->isFullyQualified()) {
            // resolve aliases (for non-relative names)
            if (!$name->isRelative() && isset($this->aliases[$name->getFirst()])) {
                $name->setFirst($this->aliases[$name->getFirst()]);
            // if no alias exists prepend current namespace
            } elseif (null !== $this->namespace) {
                $name->prepend($this->namespace);
            }
        }

        // finally just replace the namespace separators with underscores
        $name->set($name->toString('__'));
        $name->type = PHPParser_Node_Name::NORMAL;

        // and rewrite some special classes
        if (isset(self::$specialClasses[(string) $name])) {
            $name->set(self::$specialClasses[(string) $name]);
        }
    }

    protected function rewriteStaticOtherLookup(PHPParser_Node_Name &$name, $type) {
        // leave the fully qualified ones alone
        if (!$name->isFullyQualified()) {
            // resolve aliases for qualified names
            if ($name->isQualified() && isset($this->aliases[$name->getFirst()])) {
                $name->setFirst($this->aliases[$name->getFirst()]);
            // prepend current namespace for qualified and relative names (and unqualified ones if
            // the function/constant is not an internal one defined globally. This isn't exactly
            // PHP's behavior, but proper resolution would require runtime code insertion.)
            } elseif (null !== $this->namespace
                      && (!$name->isUnqualified() || !isset($this->internals[$type][strtolower($name)]))
            ) {
                $name->prepend($this->namespace);
            }
        }

        // finally just replace the namespace separators with underscores
        $name->set($name->toString('__'));
        $name->type = PHPParser_Node_Name::NORMAL;
    }

    protected function rewriteSpecialFunctions(PHPParser_Node_Expr_FuncCall &$node) {
        // dynamic function name TODO
        if (!$node->name instanceof PHPParser_Node_Name) {
            return;
        }

        $this->argHandler->handle($node);

        if (isset(self::$classReturnFuncs[(string) $node->name])) {
            $node = $this->createToNamespacedNode($node, true);
        }
    }

    public function handleDefineArg(PHPParser_Node_Expr $node) {
        // dynamic definition TODO
        if (!$node instanceof PHPParser_Node_Scalar_String) {
            return $node;
        }

        $this->rewriteDefinition($node->value);
        return $node;
    }

    public function handleSplAutoloadRegisterArg(PHPParser_Node_Expr $node) {
        return new PHPParser_Node_Expr_Array(
            array(
                new PHPParser_Node_Expr_ArrayItem(
                    new PHPParser_Node_Expr_New(
                        new PHPParser_Node_Name('_Closure_SPL'),
                        array(
                            new PHPParser_Node_Expr_FuncCallArg($node)
                        )
                    )
                ),
                new PHPParser_Node_Expr_ArrayItem(new PHPParser_Node_Scalar_String('call'))
            )
        );
    }

    public function handleClassNameArg(PHPParser_Node_Expr $node) {
        return $this->createFromNamespacedNode($node, true);
    }

    public function handleUnsafeClassNameArg(PHPParser_Node_Expr $node) {
        return $this->createFromNamespacedNode($node, false);
    }

    public function handleConstArg(PHPParser_Node_Expr $node) {
        return new PHPParser_Node_Expr_FuncCall(
            new PHPParser_Node_Name('_prepareConst'),
            array(new PHPParser_Node_Expr_FuncCallArg($node))
        );
    }

    protected function createFromNamespacedNode(PHPParser_Node_Expr $node, $safe = false) {
        // don't clutter code with functions if we can replace directly
        if ($node instanceof PHPParser_Node_Scalar_String) {
            return new PHPParser_Node_Scalar_String(
                str_replace('\\', '__', $node->value)
            );
        }

        if ($safe) {
            return new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Name('str_replace'),
                array(
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('\\')),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('__')),
                    new PHPParser_Node_Expr_FuncCallArg($node)
                )
            );
        } else {
            list($valueVarAssign, $valueVar) = $this->createEvalOnceExpr($node);

            return new PHPParser_Node_Expr_Ternary(
                new PHPParser_Node_Expr_FuncCall(
                    new PHPParser_Node_Name('is_string'),
                    array(new PHPParser_Node_Expr_FuncCallArg($valueVarAssign))
                ),
                $this->createFromNamespacedNode($valueVar, true),
                $valueVar
            );
        }
    }

    protected function createToNamespacedNode(PHPParser_Node_Expr $node, $safe = false) {
        // don't clutter code with functions if we can replace directly
        if ($node instanceof PHPParser_Node_Scalar_String) {
            return new PHPParser_Node_Scalar_String(
                str_replace('__', '\\', $node->value)
            );
        }

        if ($safe) {
            return new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Name('str_replace'),
                array(
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('__')),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('\\')),
                    new PHPParser_Node_Expr_FuncCallArg($node)
                )
            );
        } else {
            list($valueVarAssign, $valueVar) = $this->createEvalOnceExpr($node);

            return new PHPParser_Node_Expr_Ternary(
                new PHPParser_Node_Expr_FuncCall(
                    new PHPParser_Node_Name('is_string'),
                    array(new PHPParser_Node_Expr_FuncCallArg($valueVarAssign))
                ),
                $this->createToNamespacedNode($valueVar, true),
                $valueVar
            );
        }
    }

    // returns array where the first element should be used on the first use of the expression
    // and the second element on all further uses
    protected function createEvalOnceExpr(PHPParser_Node_Expr $node) {
        // for variables don't generate additional variable assignments
        if ($node instanceof PHPParser_Node_Expr_Variable) {
            return array($node, $node);
        } else {
            $valueVar       = new PHPParser_Node_Expr_Variable(uniqid('_value_'));
            $valueVarAssign = new PHPParser_Node_Expr_Assign($valueVar, $node);

            return array($valueVarAssign, $valueVar);
        }
    }

    // creates nodes of type ${'_value_%'.!$_value_%=$1}
    protected function createInlineExpr(PHPParser_Node_Expr $node) {
        $valueVarName = uniqid('_value_');
        return new PHPParser_Node_Expr_Variable(
            new PHPParser_Node_Expr_Concat(
                new PHPParser_Node_Scalar_String($valueVarName),
                new PHPParser_Node_Expr_BooleanNot(
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable($valueVarName),
                        $node
                    )
                )
            )
        );
    }
}