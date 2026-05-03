<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();

        DB::statement('drop index if exists personal_access_tokens_tokenable_type_tokenable_id_index');
        DB::statement('alter table personal_access_tokens alter column tokenable_id type uuid using null::uuid');
        DB::statement('create index personal_access_tokens_tokenable_type_tokenable_id_index on personal_access_tokens (tokenable_type, tokenable_id)');
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();

        DB::statement('drop index if exists personal_access_tokens_tokenable_type_tokenable_id_index');
        DB::statement('alter table personal_access_tokens alter column tokenable_id type bigint using null');
        DB::statement('create index personal_access_tokens_tokenable_type_tokenable_id_index on personal_access_tokens (tokenable_type, tokenable_id)');
    }
};
