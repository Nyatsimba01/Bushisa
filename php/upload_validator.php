<?php

declare(strict_types=1);

/**
 * Validate an uploaded profile photo.
 *
 * @param array<string, mixed> $file
 * @param int $max_size_mb
 * @return array{valid: bool, error: string|null}
 */
function validate_upload(array $file, int $max_size_mb = 5): array
{
    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    $allowedExtensions = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Upload failed.'];
    }

    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'error' => 'Invalid uploaded file.'];
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    $maxSizeBytes = $max_size_mb * 1024 * 1024;
    if ($size <= 0 || $size > $maxSizeBytes) {
        return ['valid' => false, 'error' => 'File must be ' . $max_size_mb . ' MB or smaller.'];
    }

    $name = isset($file['name']) && is_string($file['name']) ? $file['name'] : '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        return ['valid' => false, 'error' => 'Invalid file extension.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
        return ['valid' => false, 'error' => 'Invalid file type.'];
    }

    return ['valid' => true, 'error' => null];
}

/**
 * Save an uploaded file to the user upload directory.
 *
 * @param array<string, mixed> $file
 * @param int $user_id
 * @param string $base_dir
 * @return string|false
 */
function save_upload(array $file, int $user_id, string $base_dir = 'uploads')
{
    $validation = validate_upload($file);
    if (!$validation['valid']) {
        return false;
    }

    $baseDir = rtrim($base_dir, DIRECTORY_SEPARATOR);
    $userDir = $baseDir . DIRECTORY_SEPARATOR . $user_id;
    if (!is_dir($userDir) && !mkdir($userDir, 0775, true) && !is_dir($userDir)) {
        return false;
    }

    $name = isset($file['name']) && is_string($file['name']) ? $file['name'] : '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $filename = uniqid('photo_', true) . '.' . $extension;
    $destination = $userDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        return false;
    }

    if ($extension === 'jpg' || $extension === 'jpeg') {
        strip_exif($destination);
    }

    return $baseDir . '/' . $user_id . '/' . $filename;
}

/**
 * Re-encode JPEG images to strip EXIF metadata.
 *
 * @param string $filepath
 * @return void
 */
function strip_exif(string $filepath): void
{
    $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    if ($extension !== 'jpg' && $extension !== 'jpeg') {
        return;
    }

    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
        return;
    }

    $image = @imagecreatefromjpeg($filepath);
    if ($image === false) {
        return;
    }

    imagejpeg($image, $filepath, 90);
    imagedestroy($image);
}
