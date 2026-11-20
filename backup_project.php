<?php
require_once __DIR__ . '/auth.php';
require_login(['coordinator']);

if (!class_exists('ZipArchive') && (!class_exists('PharData') || !extension_loaded('zlib'))) {
    http_response_code(500);
    exit('Project backup is unavailable because neither ZIP nor TAR.GZ archive support is enabled.');
}

$project_directory = __DIR__;
$use_zip = class_exists('ZipArchive');
$backup_name = 'itrack-project-backup-' . date('Y-m-d-His') . ($use_zip ? '.zip' : '.tar.gz');
$temporary_path = tempnam(sys_get_temp_dir(), 'itrack_backup_');

if ($temporary_path === false) {
    http_response_code(500);
    exit('Unable to create a temporary backup file.');
}

if ($use_zip) {
    $backup_path = $temporary_path;
    $archive = new ZipArchive();
    if ($archive->open($backup_path, ZipArchive::OVERWRITE) !== true) {
        @unlink($backup_path);
        http_response_code(500);
        exit('Unable to create the project backup archive.');
    }
} else {
    @unlink($temporary_path);
    $backup_path = $temporary_path . '.tar';
    try {
        $archive = new PharData($backup_path);
    } catch (Throwable $e) {
        error_log('Unable to create project TAR backup: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to create the project backup archive.');
    }
}

$excluded_directories = [
    DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . '.vscode' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($project_directory, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if (!$file->isFile() || !$file->isReadable()) {
        continue;
    }

    $file_path = $file->getPathname();
    $relative_path = substr($file_path, strlen($project_directory) + 1);
    $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR . $relative_path);

    $excluded = false;
    foreach ($excluded_directories as $excluded_directory) {
        if (strpos($normalized_path . DIRECTORY_SEPARATOR, $excluded_directory) !== false) {
            $excluded = true;
            break;
        }
    }

    if ($excluded || preg_match('/\.(?:zip|tar|tar\.gz)$/i', $relative_path)) {
        continue;
    }

    $archive->addFile($file_path, str_replace('\\', '/', $relative_path));
}

if ($use_zip) {
    $archive->close();
} else {
    unset($archive);
    try {
        $archive = (new PharData($backup_path))->compress(Phar::GZ);
        unset($archive);
        @unlink($backup_path);
        $backup_path .= '.gz';
    } catch (Throwable $e) {
        @unlink($backup_path);
        error_log('Unable to compress project TAR backup: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to compress the project backup archive.');
    }
}

if (!is_file($backup_path)) {
    http_response_code(500);
    exit('The project backup could not be completed.');
}

header('Content-Type: ' . ($use_zip ? 'application/zip' : 'application/gzip'));
header('Content-Disposition: attachment; filename="' . $backup_name . '"');
header('Content-Length: ' . filesize($backup_path));
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($backup_path);
@unlink($backup_path);
exit;
