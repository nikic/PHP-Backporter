<?php

/**
 * Converts closures (i.e. with use()s) into classes and inserts a callable array:
 *     $f = function($a) use($b) { return $a + $b; };
 * ->
 *     $f = array(new Closure_XYZ(array('b' => $b)), 'call');
 *     // ...
 *     class Closure_XYZ
 *     {
 *         private $uses;
 *         public function __construct(array $uses) {
 *             $this->uses = $uses;
 *         }
 *         public function call($a) {
 *             extract($this->uses, EXTR_REFS);
 *             return $a + $b;
 *         }
 *     }
 */
class PHPBackporter_Converter_Closure extends PHPParser_NodeVisitorAbstract
{
    protected $closures;

    public function beforeTraverse(&$node) {
        $this->closures = array();
    }

    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Expr_LambdaFunc) {
            // only closures, no lambdas
            if (empty($node->useVars)) {
                return;
            }

            $name = uniqid('Closure_');

            // generate uses array
            $uses = array();
            foreach ($node->useVars as $use) {
                $uses[] = new PHPParser_Node_Expr_ArrayItem(array(
                    'key'   => new PHPParser_Node_Scalar_String($use->var),
                    'value' => new PHPParser_Node_Variable($use->var),
                    'byRef' => $use->byRef
                ));
            }

            // generate class from closure
            $this->closures[] = new PHPParser_Node_Stmt_Class(array(
                'type'       => 0,
                'name'       => $name,
                'extends'    => null,
                'implements' => array(),
                'stmts'      => array(
                    new PHPParser_Node_Stmt_Property(array(
                        'type'  => PHPParser_Node_Stmt_Class::MODIFIER_PRIVATE,
                        'props' => array(
                            new PHPParser_Node_Stmt_PropertyProperty(array(
                                'name'    => 'uses',
                                'default' => null
                            ))
                        )
                    )),
                    new PHPParser_Node_Stmt_ClassMethod(array(
                        'type'   => PHPParser_Node_Stmt_Class::MODIFIER_PUBLIC,
                        'byRef'  => false,
                        'name'   => '__construct',
                        'params' => array(
                            new PHPParser_Node_Stmt_FuncParam(array(
                                'type'    => 'array',
                                'byRef'   => false,
                                'name'    => 'uses',
                                'default' => null
                            ))
                        ),
                        'stmts'  => array(
                            new PHPParser_Node_Expr_Assign(array(
                                'var'  => new PHPParser_Node_Expr_PropertyFetch(array(
                                    'var'  => new PHPParser_Node_Variable('this'),
                                    'name' => 'uses'
                                )),
                                'expr' => new PHPParser_Node_Variable('uses')
                            ))
                        )
                    )),
                    new PHPParser_Node_Stmt_ClassMethod(array(
                        'type'   => PHPParser_Node_Stmt_Class::MODIFIER_PUBLIC,
                        'byRef'  => false,
                        'name'   => 'call',
                        'params' => $node->params,
                        'stmts'  => array_merge(
                            array(
                                new PHPParser_Node_Expr_FuncCall(array(
                                    'func' => new PHPParser_Node_Name('extract'),
                                    'args' => array(
                                        new PHPParser_Node_Expr_PropertyFetch(array(
                                            'var'  => new PHPParser_Node_Variable('this'),
                                            'name' => 'uses'
                                        )),
                                        new PHPParser_Node_Expr_ConstFetch(array(
                                            'name' => new PHPParser_Node_Name('EXTR_REFS')
                                        ))
                                    )
                                ))
                            ),
                            $node->stmts
                        )
                    )),
                )
            ));

            // return callable array
            $node = new PHPParser_Node_Expr_Array(array(
                'items' => array(
                    new PHPParser_Node_Expr_ArrayItem(array(
                        'key'   => null,
                        'value' => new PHPParser_Node_Expr_New(array(
                            'class' => new PHPParser_Node_Name($name),
                            'args'  => array(
                                new PHPParser_Node_Expr_Array(array(
                                    'items' => $uses
                                ))
                            )
                        )),
                        'byRef' => false
                    )),
                    new PHPParser_Node_Expr_ArrayItem(array(
                        'key'   => null,
                        'value' => new PHPParser_Node_Scalar_String('call'),
                        'byRef' => false
                    )),
                )
            ));
        }
    }

    public function afterTraverse(&$node) {
        // insert generated classes at end of file
        if (!empty($this->closures)) {
            foreach ($this->closures as $closure) {
                $node[] = $closure;
            }
        }
    }
}