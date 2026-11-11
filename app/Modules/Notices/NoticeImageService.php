<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Uuid;
use RuntimeException;

/**
 * Optional notice image storage (Inc-12). Files live outside the webroot under
 * storage/uploads/notices/; only a validated basename/extension is persisted.
 */
final class NoticeImageService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function baseDir(): string
    {
        return BASE_PATH . '/storage/uploads/notices';
    }

    /**
     * @param array<string, mixed>|null $file $_FILES-style entry
     * @return array{path:string,alt:?string}|null
     */
    public function store(?array $file, string $title, ?string $alt = null): ?array
    {
        if ($file === null || !isset($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The image upload failed. Please try again.');
        }

        $tmp = (string) $file['tmp_name'];

        if (!is_file($tmp) || !is_readable($tmp)) {
            throw new RuntimeException('The uploaded image is not readable.');
        }

        if (filesize($tmp) > self::MAX_BYTES) {
            throw new RuntimeException('The image must be 2 MB or smaller.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);

        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Only JPEG, PNG or WebP images are allowed.');
        }

        $info = @getimagesize($tmp);

        if ($info === false || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            throw new RuntimeException('The image file is corrupt.');
        }

        $ext = self::ALLOWED[$mime];
        $basename = Uuid::v4() . '.' . $ext;
        $dir = self::baseDir();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to store the image.');
        }

        $target = $dir . '/' . $basename;

        if (is_uploaded_file($tmp)) {
            $moved = move_uploaded_file($tmp, $target);
        } else {
            $moved = copy($tmp, $target);
        }

        if (!$moved) {
            throw new RuntimeException('Unable to store the image.');
        }

        chmod($target, 0644);

        return [
            'path' => $basename,
            'alt' => $alt === null || trim($alt) === '' ? mb_substr(trim($title), 0, 160) : mb_substr(trim($alt), 0, 160),
        ];
    }

    public function remove(?string $path): void
    {
        if ($path === null || $path === '' || basename($path) !== $path) {
            return;
        }

        $base = realpath(self::baseDir());

        if ($base === false) {
            return;
        }

        $target = realpath($base . '/' . $path);

        if ($target === false || !str_starts_with($target, $base . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return;
        }

        @unlink($target);
    }

    public function resolve(?string $path): ?string
    {
        if ($path === null || $path === '' || basename($path) !== $path) {
            return null;
        }

        $base = realpath(self::baseDir());

        if ($base === false) {
            return null;
        }

        $target = realpath($base . '/' . $path);

        return $target !== false && str_starts_with($target, $base . DIRECTORY_SEPARATOR) && is_file($target)
            ? $target
            : null;
    }

    public function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
