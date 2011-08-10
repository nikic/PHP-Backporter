PHP Backporter
==============

Currently supported
-------------------

* `const` statements (converted into `define` function calls)
* `__DIR__` magic constant (converted into `dirname(__FILE__)`)
* Lambda functions (converted into normal functions)

ToDo
----

* Namespaces
* Closures
* Dynamic scope resolution (`$className::`)
* Late static binding
* Short ternary operator
* `__callStatic` magic
* `__invoke` magic
* ...