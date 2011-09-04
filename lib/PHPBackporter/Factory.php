<?php

class PHPBackporter_Factory
{
    /**
     * Gets a NodeTraverser instance with all converters registered in the right order.
     *
     * @return PHPParser_NodeTraverser
     */
    public function getTraverser() {
        $traverser = new PHPParser_NodeTraverser;
        $traverser->addVisitor(new PHPBackporter_Converter_Dir);
        $traverser->addVisitor(new PHPBackporter_Converter_Const);
        $traverser->addVisitor(new PHPBackporter_Converter_Lambda);
        $traverser->addVisitor(new PHPBackporter_Converter_Closure);
        $traverser->addVisitor(new PHPBackporter_Converter_Namespace);

        return $traverser;
    }
}