PHP Backporter
==============

Currently supported
-------------------

* `const` statements (converted into `define` function calls)
* `__DIR__` magic constant (converted into `dirname(__FILE__)`)
* Lambda functions (converted into normal functions)
* Closures (converted into classes)
* Namespaces (converted into underscore-separated pseudo namespaces)

Limitations
-----------

### Closures ###

Closures can only be called using `call_user_func` / `call_user_func_array`, as they are represented
by a callable array. Such arrays aren't directly callable before PHP 5.4.

### Namespaces ###

The current namespaces implementation is very limited. Most notable it assumes all unqualified uses
of constants and functions are global if a global constant with such a name is defined and local if
it is not. Additionally no dynamic constructs are modified.

ToDo
----

* Dynamic scope resolution (`$className::`)
* Short ternary operator
* Late static binding (?)
* `__callStatic` magic (?)
* `__invoke` magic (?)
* `goto` (?)
* ...