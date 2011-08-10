PHP Backporter
==============

Currently supported
-------------------

* Conversion of `const` statements into `define` function calls
* Conversion of `__DIR__` to `dirname(__FILE__)`

ToDo
----

* Namespaces
* Lambda functions
* Closures
* Dynamic scope resolution (`$className::`)
* Late static binding
* Short ternary operator
* `__callStatic` magic
* `__invoke` magic
* ...