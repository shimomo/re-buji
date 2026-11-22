<?php

declare(strict_types=1);

// php build-files/zip.php SOURCE TARGET MTIME
//
// Zips SOURCE (a directory) into TARGET, with SOURCE's own name as the top folder. Uses PHP's zip extension, since
// rental-server-minded machines may lack the zip command.
//
// Each entry records its Unix mode. ZipArchive gives a folder 0777 unless told otherwise, and unzip, then scp or
// rsync, carries that to the server as it is. So folders are 0755, and files keep only their owner's execute bit.
//
// Every entry is dated MTIME (a Unix time; the release takes the commit's), so the same files always make the same zip
// and the hash shown before a release is the hash of the zip that goes up.

[$source, $target, $mtime] = array_slice($argv, 1) + [null, null, null];

if (!is_string($source) || !is_string($target) || !is_dir($source) || !is_string($mtime) || preg_match('/^[0-9]+$/', $mtime) !== 1) {
    fwrite(STDERR, "Usage: php build-files/zip.php <source directory> <target zip> <mtime>\n");
    exit(1);
}

$zip = new ZipArchive();

if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create $target\n");
    exit(1);
}

$time = (int) $mtime;
$folder = static function (string $name) use ($zip, $time): void {
    $zip->addEmptyDir($name);
    $zip->setExternalAttributesName($name . '/', ZipArchive::OPSYS_UNIX, (0o040755 << 16));
    $zip->setMtimeName($name . '/', $time);
};

$base = dirname($source);
$folder(basename($source));
$entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

/** @var SplFileInfo $entry */
foreach ($entries as $entry) {
    $name = substr($entry->getPathname(), strlen($base) + 1);

    if ($entry->isDir()) {
        $folder($name);
        continue;
    }

    $zip->addFile($entry->getPathname(), $name);
    $mode = ($entry->getPerms() & 0o100) !== 0 ? 0o100755 : 0o100644;
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    $zip->setMtimeName($name, $time);
}

if (!$zip->close()) {
    fwrite(STDERR, "Cannot write $target\n");
    exit(1);
}
