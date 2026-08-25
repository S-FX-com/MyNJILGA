<?php
/**
 * Dependency-free test runner for the plugin's pure classes.
 *
 *   php tests/run.php            # run everything
 *   php tests/run.php --filter=exempt
 *
 * Exit code 0 on success, 1 on any failure — CI-friendly. Loads only the
 * classes that don't touch WordPress, so no bootstrap of WP is needed.
 */
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

$filter = null;
foreach ( $argv as $arg ) {
    if ( strpos( $arg, '--filter=' ) === 0 ) {
        $filter = substr( $arg, 9 );
    }
}

$testClasses = [];
foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
    require_once $file;
}
foreach ( get_declared_classes() as $class ) {
    if ( is_subclass_of( $class, 'NJILGA_TestCase' ) ) {
        $testClasses[] = $class;
    }
}

$passed = 0; $failed = 0; $failures = [];
foreach ( $testClasses as $class ) {
    $instance = new $class();
    foreach ( get_class_methods( $class ) as $method ) {
        if ( strpos( $method, 'test' ) !== 0 ) {
            continue;
        }
        if ( $filter !== null && stripos( $method, $filter ) === false ) {
            continue;
        }
        try {
            $instance->$method();
            $passed++;
            echo "  ✓ $class::$method\n";
        } catch ( Throwable $e ) {
            $failed++;
            $failures[] = "$class::$method\n      " . $e->getMessage();
            echo "  ✗ $class::$method\n      " . $e->getMessage() . "\n";
        }
    }
}

echo "\n";
if ( $failures ) {
    echo "FAILURES:\n";
    foreach ( $failures as $f ) {
        echo "  - $f\n";
    }
    echo "\n";
}
printf( "%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
