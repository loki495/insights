<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/resources/views',
        __DIR__.'/tests',
    ])
    ->withSkip([
        DeclareStrictTypesRector::class => [__DIR__.'/resources/views'],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        strictBooleans: true,
    )
    ->withRules([
        DeclareStrictTypesRector::class,
    ])
    ->withPhpSets();
