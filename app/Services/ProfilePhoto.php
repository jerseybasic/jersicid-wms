<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Foto profil: dipotong persegi dari tengah, diperkecil ke 256 px, dan disimpan sebagai JPG di disk
 * privat (storage/app/private), lalu ditampilkan lewat route yang butuh login. Tidak perlu storage:link.
 */
class ProfilePhoto
{
    public const SIZE = 256;

    public const DIR = 'profile-photos';

    public static function store(User $user, UploadedFile $file): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (!$source) {
            throw new \RuntimeException('Gambar tidak bisa dibaca. Pakai file JPG, PNG, atau WEBP.');
        }

        $source = self::orient($source, $file);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $photo = imagecreatetruecolor(self::SIZE, self::SIZE);
        // Latar putih untuk PNG transparan, karena hasilnya JPG
        imagefill($photo, 0, 0, imagecolorallocate($photo, 255, 255, 255));
        imagecopyresampled($photo, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($photo, null, 85);
        $jpg = ob_get_clean();
        imagedestroy($source);
        imagedestroy($photo);

        // Nama berubah setiap upload supaya browser tidak menampilkan foto lama dari cache
        $path = self::DIR . '/' . $user->id . '-' . now()->format('YmdHis') . '.jpg';
        Storage::disk('local')->put($path, $jpg);

        $old = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    public static function remove(User $user): void
    {
        if ($user->profile_photo_path) {
            Storage::disk('local')->delete($user->profile_photo_path);
            $user->forceFill(['profile_photo_path' => null])->save();
        }
    }

    /** Foto dari kamera HP sering tersimpan miring; putar sesuai data EXIF-nya */
    private static function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (!function_exists('exif_read_data') || !in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $angle = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1)] ?? 0;

        return $angle ? imagerotate($image, $angle, 0) : $image;
    }
}
