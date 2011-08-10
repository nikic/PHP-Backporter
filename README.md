PHP Backporter
==============

Currently supported
-------------------

* `const` statements (converted into `define` function calls)
* `__DIR__` magic constant (converted into `dirname(__FILE__)`)
* Lambda functions (converted into normal functions)
* Closures (converted into classes)

Limitations
-----------

Closures can only be called using `call_user_func` / `call_user_func_array`, as they are represented
by a callable array. Such arrays aren't directly callable before PHP 5.4.

ToDo
----

* Namespaces
* Dynamic scope resolution (`$className::`)
* Short ternary operator
* Late static binding (?)
* `__callStatic` magic (?)
* `__invoke` magic (?)
* `goto` (?)
* ...