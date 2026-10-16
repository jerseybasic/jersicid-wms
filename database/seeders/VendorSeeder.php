<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vendor;
use Illuminate\Support\Str;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'name' => 'JB Printing',
                'type' => 'SUBLIMASI',
                'contact_person' => 'Admin JB',
                'phone' => '-',
                'email' => '-',
                'address' => '-',
                'status' => 'AKTIF',
            ],
            [
                'name' => 'SMK Konveksi',
                'type' => 'KONVEKSI',
                'contact_person' => 'Admin SMK',
                'phone' => '-',
                'email' => '-',
                'address' => '-',
                'status' => 'AKTIF',
            ],
        ];

        foreach ($vendors as $vendor) {
            Vendor::create(array_merge($vendor, [
                'id' => (string) Str::uuid(),
            ]));
        }

        echo "✅ " . count($vendors) . " vendor berhasil dibuat.\n";
    }
}