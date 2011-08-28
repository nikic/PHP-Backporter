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

    protected static $classFuncs = array(
        'class_exists'            => 0,
        'interface_exists'        => 0,
        'is_a'                    => 1,
        'is_subclass_of'          => 1,
        'mysql_fetch_object'      => 1,
        'simplexml_import_dom'    => 1,
        'simplexml_load_file'     => 1,
        'simplexml_load_string'   => 1,
        'spl_autoload'            => 0,
        'spl_autoload_call'       => 0,
        'sqlite_fetch_object'     => 1,
        'stream_filter_register'  => 1,
        'stream_register_wrapper' => 1,
        'stream_wrapper_register' => 1,
    );

    protected static $objectOrClassFuncs = array(
        'get_class_vars'    => 0,
        'get_class_methods' => 0,
        'get_parent_class'  => 0,
        'is_a'              => 0,
        'is_subclass_of'    => 0,
        'property_exists'   => 0,
    );

    protected static $classReturnFuncs = array(
        'get_class'        => true,
        'get_parent_class' => true,
    );

    protected static $specialClasses = array(
        'ReflectionClass'  => '_ReflectionClass',
        'ReflectionObject' => '_ReflectionObject',
    );

    public function beforeTraverse(&$node) {
        $this->namespace = null;
        $this->aliases   = array();

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
        } elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
            $this->rewriteLookup($node->name, T_FUNCTION);

            return $this->rewriteSpecialFunctions($node);
        } elseif ($node instanceof PHPParser_Node_Expr_ConstFetch) {
            $this->rewriteLookup($node->name, T_CONST);
        } elseif ($node instanceof PHPParser_Node_Param
                  && $node->type instanceof PHPParser_Node_Name
        ) {
            $this->rewriteStaticClassLookup($node->type);
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
        $name->set($name->toString('_'));
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
        $name->set($name->toString('_'));
        $name->type = PHPParser_Node_Name::NORMAL;
    }

    protected function rewriteSpecialFunctions(PHPParser_Node_Expr_FuncCall &$node) {
        // dynamic function name TODO
        if (!$node->name instanceof PHPParser_Node_Name) {
            return;
        }

        if ('define' == $node->name) {
            return $this->rewriteDefineFunction($node);
        } elseif ('spl_autoload_register' == $node->name) {
            return $this->rewriteSplAutoloadRegisterFunction($node);
        } else {
            if (isset(self::$classFuncs[(string) $node->name])) {
                $argN = self::$classFuncs[(string) $node->name];
                if (isset($node->args[$argN])) {
                    $arg = $node->args[$argN];
                    $arg->value = $this->createFromNamespacedNode($arg->value, true);
                }
            }

            if (isset(self::$objectOrClassFuncs[(string) $node->name])) {
                $argN = self::$objectOrClassFuncs[(string) $node->name];
                if (isset($node->args[$argN])) {
                    $arg = $node->args[$argN];
                    $arg->value = $this->createFromNamespacedNode($arg->value, false);
                }
            }

            if (isset(self::$classReturnFuncs[(string) $node->name])) {
                $node = $this->createToNamespacedNode($node, true);
            }
        }
    }

    // rewrites constants defined using the define() function
    protected function rewriteDefineFunction(PHPParser_Node_Expr_FuncCall &$node) {
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

    // spl_autoload_register callbacks need to be passed the class name
    // with \ instead of _
    protected function rewriteSplAutoloadRegisterFunction(PHPParser_Node_Expr_FuncCall &$node) {
        if (!isset($node->args[0])) {
            return;
        }

        $callbackVarName = uniqid('_callback_');
        $assignment = new PHPParser_Node_Expr_Assign(
            new PHPParser_Node_Expr_Variable($callbackVarName),
            $node->args[0]
        );
        $node->args[0] = new PHPParser_Node_Expr_LambdaFunc(
            array(
                new PHPParser_Node_Stmt_Return(array(
                    'expr' => new PHPParser_Node_Expr_FuncCall(
                        new PHPParser_Node_Name('call_user_func'),
                        array(
                            new PHPParser_Node_Expr_FuncCallArg(
                                new PHPParser_Node_Expr_Variable($callbackVarName)
                            ),
                            new PHPParser_Node_Expr_FuncCallArg(
                                $this->createToNamespacedNode(
                                    new PHPParser_Node_Expr_Variable('class'),
                                    true
                                )
                            )
                        )
                    )
                ))
            ),
            array(new PHPParser_Node_Param('class')),
            array(new PHPParser_Node_Expr_LambdaFuncUse($callbackVarName))
        );

        return array($assignment, $node);
    }

    protected function createFromNamespacedNode(PHPParser_Node_Expr $node, $safe = false) {
        // don't clutter code with functions if we can replace directly
        if ($node instanceof PHPParser_Node_Scalar_String) {
            return new PHPParser_Node_Scalar_String(
                strtr($node->value, '\\', '_')
            );
        }

        if ($safe) {
            return new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Name('strtr'),
                array(
                    new PHPParser_Node_Expr_FuncCallArg($node),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('\\')),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('_'))
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
                strtr($node->value, '_', '\\')
            );
        }

        if ($safe) {
            return new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Name('strtr'),
                array(
                    new PHPParser_Node_Expr_FuncCallArg($node),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('_')),
                    new PHPParser_Node_Expr_FuncCallArg(new PHPParser_Node_Scalar_String('\\'))
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