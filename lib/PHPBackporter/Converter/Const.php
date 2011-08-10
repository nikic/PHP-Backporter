<?php

/**
 * Converts const to define:
 *     const FOO = BAR;
 * ->
 *     define('FOO', BAR);
 */
class PHPBackporter_Converter_Const extends PHPParser_NodeVisitorAbstract
{
    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Stmt_Const) {
            foreach ($node->consts as &$const) {
                $const = new PHPParser_Node_Expr_FuncCall(array(
                    'func' => new PHPParser_Node_Name('defined', PHPParser_Node_Name::FULLY_QUALIFIED),
                    'args' => array(
                        new PHPParser_Node_Expr_FuncCallArg(array(
                            'value' => new PHPParser_Node_Scalar_String($node->name),
                            'byRef' => false
                        ))
                    )
                ));
            }

            return $node->consts;
        }
    }
}