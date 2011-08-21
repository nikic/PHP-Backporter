<?php

/**
 * Converts lambda functions (i.e. without use()s!) into normal functions and inserts the callable
 * function name:
 *     $f = function($a, $b) { return $a + $b; };
 * ->
 *     $f = 'lambda_XYZ';
 *     // ...
 *     function lambda_XYZ($a, $b) { return $a + $b; }
 */
class PHPBackporter_Converter_Lambda extends PHPParser_NodeVisitorAbstract
{
    protected $lambdas;

    public function beforeTraverse(&$node) {
        $this->lambdas = array();
    }

    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Expr_LambdaFunc) {
            // only lambdas, no closures
            if (!empty($node->uses)) {
                return;
            }

            $name = uniqid('lambda_');

            // generate real function from lambda
            $this->lambdas[] = new PHPParser_Node_Stmt_Func(array(
                'byRef'  => $node->byRef,
                'name'   => $name,
                'params' => $node->params,
                'stmts'  => $node->stmts
            ));

            // return name as string (callable)
            $node = new PHPParser_Node_Scalar_String($name);
        }
    }

    public function afterTraverse(&$node) {
        // insert generated functions at end of file
        if (!empty($this->lambdas)) {
            foreach ($this->lambdas as $lamnbda) {
                $node[] = $lamnbda;
            }
        }
    }
}