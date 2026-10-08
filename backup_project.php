<?php
require_once __DIR__ . '/auth.php';
require_login(['coordinator']);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Project backup is unavailable because the PHP Zip extension is not enabled.');
}

$project_directory = __DIR__;
$backup_name = 'itrack-project-backup-' . date('Y-m-d-His') . '.zip';
$backup_path = tempnam(sys_get_temp_dir(), 'itrack_backup_');

if ($backup_path === false) {
    http_response_code(500);
    exit('Unable to create a temporary backup file.');
}

$zip = new ZipArchive();
if ($zip->open($backup_path, ZipArchive::OVERWRITE) !== true) {
    @unlink($backup_path);
    http_response_code(500);
    exit('Unable to create the project backup archive.');
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

    if ($excluded || preg_match('/\.zip$/i', $relative_path)) {
        continue;
    }

    $zip->addFile($file_path, str_replace('\\', '/', $relative_path));
}

$zip->close();

if (!is_file($backup_path)) {
    http_response_code(500);
    exit('The project backup could not be completed.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $backup_name . '"');
header('Content-Length: ' . filesize($backup_path));
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($backup_path);
@unlink($backup_path);
exit;
