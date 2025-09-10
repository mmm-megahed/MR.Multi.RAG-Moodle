<?php
require_once('../../config.php');

$path = required_param('path', PARAM_PATH);
$courseid = optional_param('courseid', 0, PARAM_INT);

require_login();

// Debug: Check dataroot path
error_log("CFG dataroot: " . $CFG->dataroot);
error_log("Requested path: " . $path);

$dataroot = $CFG->dataroot;

// Clean the path - ensure it starts with /
if (!str_starts_with($path, '/')) {
    $path = '/' . $path;
}

$full_path = $dataroot . $path;

error_log("Full path: " . $full_path);
error_log("File exists: " . (file_exists($full_path) ? 'YES' : 'NO'));

// List directory contents for debugging
$dir = dirname($full_path);
if (is_dir($dir)) {
    error_log("Directory contents of " . $dir . ": " . implode(', ', scandir($dir)));
}

// Updated allowed paths to match where images are actually stored
$allowed_paths = [
    '/temp/images/',
    '/temp/extracted_images/',
    '/blocks/multimodalrag/images/',
    '/filedir/' // Allow access to moodle filedir structure
];

$is_allowed = false;
foreach ($allowed_paths as $allowed_path) {
    if (strpos($path, $allowed_path) === 0) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    error_log("Access denied for path: " . $path);
    header('HTTP/1.0 403 Forbidden');
    die('Access denied: ' . htmlspecialchars($path));
}

if (!file_exists($full_path)) {
    error_log("File not found: " . $full_path);
    
    // Try alternative paths if the direct path doesn't work
    $alternative_paths = [
        $dataroot . '/temp/extracted_images/' . basename($path),
        $dataroot . '/temp/images/' . basename($path)
    ];
    
    $found = false;
    foreach ($alternative_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $full_path = $alt_path;
            $found = true;
            error_log("Found file at alternative path: " . $alt_path);
            break;
        }
    }
    
    if (!$found) {
        // List all files in the extracted_images directory for debugging
        $extracted_dir = $dataroot . '/temp/extracted_images';
        if (is_dir($extracted_dir)) {
            $files = scandir($extracted_dir);
            error_log("Files in extracted_images: " . implode(', ', $files));
        }
        
        header('HTTP/1.0 404 Not Found');
        die('File not found: ' . htmlspecialchars($full_path));
    }
}

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
$file_extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));

if (!in_array($file_extension, $allowed_extensions)) {
    header('HTTP/1.0 403 Forbidden');
    die('File type not allowed: ' . htmlspecialchars($file_extension));
}

$mime_types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp'
];

$mime_type = $mime_types[$file_extension] ?? 'application/octet-stream';

// Set appropriate headers
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// Output the file
readfile($full_path);
?>