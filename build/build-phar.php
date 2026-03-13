<?php
declare(strict_types=1);
/**
 * PHAR build script for lphenom/realtime.
 *
 * Packages src/ + vendor/ into a compressed, self-contained PHAR archive.
 * Run with phar.readonly=0:
 *   php -d phar.readonly=0 build/build-phar.php
 */
$buildDir = dirname(__DIR__);
$pharFile = $buildDir . '/lphenom-realtime.phar';
if (file_exists($pharFile)) {
    unlink($pharFile);
}
$phar = new Phar($pharFile, 0, 'lphenom-realtime.phar');
$phar->startBuffering();
// Add all source files from src/
$srcBase     = $buildDir . '/src';
$srcIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcBase, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($srcIterator as $file) {
    /** @var SplFileInfo $file */
    $localPath = 'src/' . ltrim(str_replace($srcBase, '', $file->getPathname()), '/');
    $phar->addFile($file->getPathname(), $localPath);
}
// Add vendor autoloader (production deps only — no dev packages)
$vendorBase = $buildDir . '/vendor';
if (is_dir($vendorBase)) {
    $vendorIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendorBase, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($vendorIterator as $file) {
        /** @var SplFileInfo $file */
        $localPath = 'vendor/' . ltrim(str_replace($vendorBase, '', $file->getPathname()), '/');
        $phar->addFile($file->getPathname(), $localPath);
    }
}
// Bootstrap stub — loads the Composer autoloader when PHAR is required
$stub = <<<'STUB'
<?php
Phar::mapPhar('lphenom-realtime.phar');
require 'phar://lphenom-realtime.phar/vendor/autoload.php';
__HALT_COMPILER();
STUB;
$phar->setStub($stub);
$phar->stopBuffering();
// Compress all files with GZ
$phar->compressFiles(Phar::GZ);
$size  = number_format((int) filesize($pharFile));
$count = count($phar);
echo 'PHAR built: ' . $pharFile . PHP_EOL;
echo '  Size:  ' . $size . ' bytes' . PHP_EOL;
echo '  Files: ' . $count . PHP_EOL;
echo '=== PHAR build: OK ===' . PHP_EOL;
