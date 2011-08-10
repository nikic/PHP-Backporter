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
    protected $funcs;

    public function beforeTraverse(&$node) {
        $this->funcs = array();
    }

    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Expr_LambdaFunc) {
            // only lambdas, no closures
            if (!empty($node->useVars)) {
                return;
            }

            $name = uniqid('lambda_');

            // generate real function from lambda
            $this->funcs[] = new PHPParser_Node_Stmt_Func(array(
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
        if (!empty($this->funcs)) {
            foreach ($this->funcs as $func) {
                $node[] = $func;
            }
        }
    }
}