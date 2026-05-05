<?php

declare(strict_types=1);

/*
 * phpunit bootstrap for functional tests.
 *
 * Adapted from typo3/testing-framework's
 * Resources/Core/Build/FunctionalTestsBootstrap.php and kept minimal.
 */

(static function () {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
