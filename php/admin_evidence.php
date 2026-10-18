<?php
declare(strict_types=1);

/** Resolve a private evidence file without exposing or trusting a public URL. */
function admin_evidence_open(string $reference): ?array
{
    if ($reference === '' || str_contains($reference, "\0") || preg_match('~^[a-z]+://~i', $reference)) return null;
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
    $configured = getenv('BUSHISA_EVIDENCE_DIR') ?: dirname($documentRoot) . '/bushisa-private/evidence';
    $root = realpath($configured);
    if ($root === false || !is_dir($root)) return null;
    $normalise = static function (string $path): string {
        $path = rtrim(str_replace('\\','/',$path),'/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    $rootPath = $normalise($root);
    // Require storage outside both the web document root and the project.
    foreach ([$documentRoot, dirname(__DIR__)] as $publicRoot) {
        $publicPath = $normalise($publicRoot);
        if ($rootPath === $publicPath || str_starts_with($rootPath,$publicPath . '/') || str_starts_with($publicPath,$rootPath . '/')) return null;
    }
    $reference = str_replace('\\','/',$reference);
    if (in_array('..',explode('/',$reference),true)) return null;
    $absolute = str_starts_with($reference,'/') || preg_match('~^[A-Za-z]:/~',$reference);
    // Old references remain usable after copying their file into private storage.
    if (!$absolute && str_starts_with($reference,'uploads/evidence/')) $reference = substr($reference,17);
    $resolved = realpath($absolute ? $reference : $root . '/' . $reference);
    if ($resolved === false || !str_starts_with($normalise($resolved),$rootPath . '/') || !is_file($resolved)) return null;
    $extension = strtolower(pathinfo($resolved,PATHINFO_EXTENSION));
    $types = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];
    if (!isset($types[$extension]) || !class_exists('finfo')) return null;
    $stream = @fopen($resolved,'rb');
    if ($stream === false) return null;
    $stat = fstat($stream);
    if (!$stat || $stat['size'] < 1 || $stat['size'] > 10*1024*1024) { fclose($stream); return null; }
    $sample = fread($stream,65536);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample ?: '');
    rewind($stream);
    if ($mime !== $types[$extension]) { fclose($stream); return null; }
    if ($mime !== 'application/pdf') {
        $image = @getimagesize($resolved);
        if (!$image || $image[0] < 1 || $image[1] < 1 || $image[0]*$image[1] > 40000000) { fclose($stream); return null; }
    }
    return ['stream'=>$stream,'mime'=>$mime,'extension'=>$extension,'size'=>$stat['size'],'download'=>$mime==='application/pdf'];
}
