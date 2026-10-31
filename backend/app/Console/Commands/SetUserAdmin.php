<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserAdmin extends Command
{
    protected $signature = 'user:admin {email : 대상 사용자의 이메일} {--revoke : 관리자 권한을 해제한다}';

    protected $description = '사용자를 관리자로 지정하거나(기본) 해제한다(--revoke)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('해당 이메일의 사용자가 없습니다.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->email.($user->is_admin ? ' 을(를) 관리자로 지정했습니다.' : ' 의 관리자 권한을 해제했습니다.'));

        return self::SUCCESS;
    }
}
