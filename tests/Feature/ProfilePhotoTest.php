<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Foto profil: upload dari halaman Profil, tampil di header dan Kelola User, bisa diganti dan dihapus.
 */
class ProfilePhotoTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Rina Gudang', 'email' => 'rina@test.id'];
    }

    public function test_user_uploads_changes_and_removes_profile_photo(): void
    {
        Storage::fake('local');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $staff->update(['name' => 'Rina Gudang', 'email' => 'rina@test.id']);

        // Belum ada foto: inisial yang tampil
        $this->actingAs($staff)->get(route('settings.update-profile'))->assertOk()->assertSee('RG')->assertSee('Pilih foto');
        $this->assertNull($staff->profile_photo_url);

        $this->put(route('settings.profile.update'), $this->form(['photo' => UploadedFile::fake()->image('saya.png', 800, 600)]))
            ->assertSessionHasNoErrors();
        $first = $staff->fresh()->profile_photo_path;
        $this->assertStringStartsWith('profile-photos/' . $staff->id, $first);
        Storage::disk('local')->assertExists($first);

        // Dipotong persegi 256 px dan disimpan sebagai JPG
        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($first));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$width, $height, $type]);

        $url = $staff->fresh()->profile_photo_url;
        $this->get(route('settings.update-profile'))->assertSee($url, false)->assertSee('Ganti foto');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Ganti foto: file lama dihapus
        $this->travel(2)->seconds();
        $this->put(route('settings.profile.update'), $this->form(['photo' => UploadedFile::fake()->image('baru.jpg', 300, 500)]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($staff->fresh()->profile_photo_path);

        // Hapus foto
        $second = $staff->fresh()->profile_photo_path;
        $this->put(route('settings.profile.update'), $this->form(['remove_photo' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($staff->fresh()->profile_photo_path);
        Storage::disk('local')->assertMissing($second);
        $this->get(route('settings.photo', $staff->id))->assertNotFound();

        $this->assertSame(3, AuditTrail::where('action', 'UPDATE_PROFILE')->count());
        $this->assertSame('Dihapus', AuditTrail::where('action', 'UPDATE_PROFILE')->latest('id')->first()->valuesOf('new_values')['foto_profil'] ?? null);
    }

    public function test_photo_must_be_an_image_and_needs_login(): void
    {
        Storage::fake('local');
        $staff = $this->userWithRole('STAFF_GUDANG');

        $this->actingAs($staff)->put(route('settings.profile.update'), $this->form(['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('photo');
        $this->assertNull($staff->fresh()->profile_photo_path);

        $this->put(route('settings.profile.update'), $this->form(['photo' => UploadedFile::fake()->image('a.jpg')]));
        $url = $staff->fresh()->profile_photo_url;

        // Owner melihat foto anggota di Kelola User
        $this->actingAs($this->userWithRole('OWNER'))->get(route('users.index'))->assertSee($url, false);

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }
}
