<?php

/**
 * Converts __DIR__ to dirname(__FILE__).
 */
class PHPBackporter_Converter_Dir extends PHPParser_NodeVisitorAbstract
{
    public function leaveNode(PHPParser_NodeAbstract &$node) {
        if ($node instanceof PHPParser_Node_Scalar_DirConst) {
            $node = new PHPParser_Node_Expr_FuncCall(array(
                'func' => new PHPParser_Node_Name('dirname'),
                'args' => array(
                    new PHPParser_Node_Expr_FuncCallArg(array(
                        'value' => new PHPParser_Node_Scalar_FileConst(),
                        'byRef' => false
                    ))
                )
            ));
        }
    }
}