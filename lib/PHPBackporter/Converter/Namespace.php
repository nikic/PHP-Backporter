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

            return $this->rewriteSpecialFunctions($node);
        } elseif ($node instanceof PHPParser_Node_Expr_ConstFetch) {
            $this->rewriteLookup($node->name, T_CONST);
        } elseif ($node instanceof PHPParser_Node_Param) {
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

        // don't try to resolve special class names
        if (T_CLASS == $type && in_array((string) $name, array('self', 'parent', 'static'))) {
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
                    if ($arg->value instanceof PHPParser_Node_Scalar_String) {
                        $arg->value->value = strtr($arg->value->value, '\\', '_');
                    } else {
                        $arg->value = new PHPParser_Node_Expr_FuncCall(
                            new PHPParser_Node_Name('strtr'),
                            array(
                                 new PHPParser_Node_Expr_FuncCallArg(
                                     $arg->value
                                 ),
                                 new PHPParser_Node_Expr_FuncCallArg(
                                     new PHPParser_Node_Scalar_String('\\')
                                 ),
                                 new PHPParser_Node_Expr_FuncCallArg(
                                     new PHPParser_Node_Scalar_String('_')
                                 )
                            )
                        );
                    }
                }
            }

            if (isset(self::$objectOrClassFuncs[(string) $node->name])) {
                $argN = self::$objectOrClassFuncs[(string) $node->name];
                if (isset($node->args[$argN])) {
                    $arg = $node->args[$argN];
                    if ($arg->value instanceof PHPParser_Node_Scalar_String) {
                        $arg->value->value = strtr($arg->value->value, '\\', '_');
                    } else {
                        $valueVar = null;
                        if (!$arg->value instanceof PHPParser_Node_Expr_Variable) {
                            $valueVar = new PHPParser_Node_Expr_Variable(uniqid('value_'));
                        }

                        $arg->value = new PHPParser_Node_Expr_Ternary(
                            new PHPParser_Node_Expr_FuncCall(
                                new PHPParser_Node_Name('is_string'),
                                array(
                                    new PHPParser_Node_Expr_FuncCallArg(
                                        $valueVar
                                        ? new PHPParser_Node_Expr_Assign(
                                            $valueVar,
                                            $node->args[$argN]->value
                                        )
                                        : $node->args[$argN]->value
                                    )
                                )
                            ),
                            new PHPParser_Node_Expr_FuncCall(
                                new PHPParser_Node_Name('strtr'),
                                array(
                                     new PHPParser_Node_Expr_FuncCallArg(
                                         $valueVar ? $valueVar : $arg->value
                                     ),
                                     new PHPParser_Node_Expr_FuncCallArg(
                                         new PHPParser_Node_Scalar_String('\\')
                                     ),
                                     new PHPParser_Node_Expr_FuncCallArg(
                                         new PHPParser_Node_Scalar_String('_')
                                     )
                                )
                            ),
                            $valueVar ? $valueVar : $arg->value
                        );
                    }
                }
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

        $callbackVarName = uniqid('callback_');
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
                                new PHPParser_Node_Expr_FuncCall(
                                    new PHPParser_Node_Name('strtr'),
                                    array(
                                        new PHPParser_Node_Expr_FuncCallArg(
                                            new PHPParser_Node_Expr_Variable('class')
                                        ),
                                        new PHPParser_Node_Expr_FuncCallArg(
                                            new PHPParser_Node_Scalar_String('_')
                                        ),
                                        new PHPParser_Node_Expr_FuncCallArg(
                                            new PHPParser_Node_Scalar_String('\\')
                                        ),
                                    )
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
}