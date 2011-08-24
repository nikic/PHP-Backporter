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
            if (empty($node->uses)) {
                return;
            }

            $name = uniqid('_Closure_');

            // generate uses array
            $uses = array();
            foreach ($node->uses as $use) {
                $uses[] = new PHPParser_Node_Expr_ArrayItem(
                    new PHPParser_Node_Scalar_String($use->var),
                    new PHPParser_Node_Expr_Variable($use->var),
                    $use->byRef
                );
            }

            // generate class from closure
            $this->closures[] = new PHPParser_Node_Stmt_Class(array(
                'type'       => 0,
                'name'       => $name,
                'extends'    => new PHPParser_Node_Name('_Closure'),
                'implements' => array(),
                'stmts'      => array(
                    new PHPParser_Node_Stmt_ClassMethod(array(
                        'type'   => PHPParser_Node_Stmt_Class::MODIFIER_PUBLIC,
                        'byRef'  => false,
                        'name'   => 'call',
                        'params' => $node->params,
                        'stmts'  => array_merge(
                            array(
                                new PHPParser_Node_Expr_FuncCall(
                                    new PHPParser_Node_Name('extract'),
                                    array(
                                        new PHPParser_Node_Expr_PropertyFetch(
                                            new PHPParser_Node_Expr_Variable('this'), 'uses'
                                        ),
                                        new PHPParser_Node_Expr_ConstFetch(
                                            new PHPParser_Node_Name('EXTR_REFS')
                                        )
                                    )
                                )
                            ),
                            $node->stmts
                        )
                    )),
                )
            ));

            // return callable array
            $node = new PHPParser_Node_Expr_Array(array(
                new PHPParser_Node_Expr_ArrayItem(
                    null,
                    new PHPParser_Node_Expr_New(
                        new PHPParser_Node_Name($name),
                        array(
                            new PHPParser_Node_Expr_Array($uses)
                        )
                    )
                ),
                new PHPParser_Node_Expr_ArrayItem(
                    null,
                    new PHPParser_Node_Scalar_String('call')
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