<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Tạo người dùng thử nghiệm
        $sellerId = DB::table('users')->insertGetId([
            'name'           => 'Nguyen Van Seller',
            'full_name'      => 'Nguyễn Văn Người Bán',
            'email'          => 'seller@hunre.edu.vn',
            'password'       => Hash::make('123456'),
            'wallet_balance' => 0.00,
            'created_at'     => now(),
            'updated_at'     => now()
        ]);

        $buyerId = DB::table('users')->insertGetId([
            'name'           => 'Tran Thi Buyer',
            'full_name'      => 'Trần Thị Người Mua',
            'email'          => 'buyer@hunre.edu.vn',
            'password'       => Hash::make('123456'),
            'wallet_balance' => 1000000.00,
            'created_at'     => now(),
            'updated_at'     => now()
        ]);

        // 2. Tạo Trạm Hub & Tủ Locker
        $hubId = DB::table('hubs')->insertGetId([
            'name'            => 'Trạm Smart Hub HUNRE - Cơ sở Cầu Giấy',
            'location_detail' => '218 Đường Hoàng Quốc Việt, Cầu Giấy, Hà Nội',
            'created_at'      => now(),
            'updated_at'      => now()
        ]);

        $locker1Id = DB::table('lockers')->insertGetId([
            'hub_id'      => $hubId,
            'locker_code' => 'LOCKER-A-01',
            'created_at'  => now(),
            'updated_at'  => now()
        ]);

        $locker2Id = DB::table('lockers')->insertGetId([
            'hub_id'      => $hubId,
            'locker_code' => 'LOCKER-A-02',
            'created_at'  => now(),
            'updated_at'  => now()
        ]);

        // 3. Tạo Sản phẩm bán thử nghiệm
        DB::table('products')->insert([
            [
                'seller_id'     => $sellerId,
                'title'         => 'Sách Giáo Trình Kiến Trúc Phần Mềm & Saga Pattern',
                'current_price' => 120000.00,
                'status'        => 'ACTIVE',
                'created_at'    => now(),
                'updated_at'    => now()
            ],
            [
                'seller_id'     => $sellerId,
                'title'         => 'Laptop ThinkPad X1 Carbon Gen 9 - Like New',
                'current_price' => 8500000.00,
                'status'        => 'ACTIVE',
                'created_at'    => now(),
                'updated_at'    => now()
            ]
        ]);
    }
}
