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

Closures are represented by a callable array, so calls of type $function() will be transformed
into call_user_func(_array) calls. This can be a problem is functions expect arguments by reference.

### Namespaces ###

The current namespaces implementation is very limited. Most notably it assumes all unqualified uses
of constants and functions are global if a global constant with such a name is defined and local if
it is not. Additionally many dynamic constructs do not work yet.

ToDo
----

* Dynamic scope resolution (`$className::`)
* Short ternary operator
* Late static binding (?)
* `__callStatic` magic (?)
* `__invoke` magic (?)
* `goto` (?)
* ...