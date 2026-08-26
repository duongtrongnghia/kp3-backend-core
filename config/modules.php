<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Modules
    |--------------------------------------------------------------------------
    | PascalCase module names (folder under modules/). Each must contain a
    | module.json manifest. Dependencies (module.json "depends") load
    | automatically in topological order. To enable a module: drop it into
    | modules/ and add its name here.
    |
    | In the testing environment ALL modules are loaded regardless of this
    | list, so cross-module hook chains can be exercised.
    */
    'enabled' => [
        'Example',
    ],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Relation Bindings
    |--------------------------------------------------------------------------
    | Wire cross-module relations without coupling. Shape:
    |   'TargetModule' => [
    |       'SourceModule' => [ ...binding config... ],
    |   ],
    | Source module providers expose getModelRelations/getInverseRelations/
    | getContentHooks. Leave empty when modules are independent.
    */
    'bindings' => [],

];
