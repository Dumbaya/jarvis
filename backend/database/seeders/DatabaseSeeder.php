<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * 서비스 레지스트리 데이터만 시딩한다. 운영 DB에 비밀번호가 알려진 테스트 계정이 생기지 않게 사용자는 만들지 않는다.
     */
    public function run(): void
    {
        $this->call(SecondBrainToolsSeeder::class);
    }
}
