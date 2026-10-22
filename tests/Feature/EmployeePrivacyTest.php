<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class EmployeePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
    }

    public function test_identifiers_are_encrypted_at_rest_and_read_as_plaintext(): void
    {
        $user = User::factory()->create([
            'name' => 'Rider Uji Privasi',
            'national_id' => '3171234567890001',
            'bank_account_number' => '7001234567',
        ]);

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame('3171234567890001', $raw->national_id);
        $this->assertNotSame('7001234567', $raw->bank_account_number);
        $this->assertSame('3171234567890001', $user->fresh()->national_id);
        $this->assertSame('7001234567', $user->fresh()->bank_account_number);
    }

    public function test_empty_identifiers_are_stored_as_null(): void
    {
        $user = User::factory()->create([
            'national_id' => '',
            'bank_account_number' => '',
        ]);

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('national_id'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('bank_account_number'));
        $this->assertNull($user->fresh()->national_id);
        $this->assertNull($user->fresh()->bank_account_number);

        $user->update(['national_id' => '   ', 'bank_account_number' => '']);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('national_id'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('bank_account_number'));
    }

    public function test_user_serialization_omits_decrypted_identifiers(): void
    {
        $user = User::factory()->create([
            'national_id' => '3171234567890001',
            'bank_account_number' => '7001234567',
        ]);

        $this->assertArrayNotHasKey('national_id', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('bank_account_number', $user->fresh()->toArray());
    }

    public function test_administrator_can_provision_and_modify_management_users(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $ordinary = User::factory()->role(Role::STAFF)->create();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Management Uji Baru',
                'phone_e164' => '081298760099',
                'role' => Role::MANAGEMENT->value,
                'password' => 'rahasia123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['phone_e164' => '081298760099', 'role' => Role::MANAGEMENT->value]);

        Livewire::test(EditUser::class, ['record' => $ordinary->id])
            ->fillForm(['role' => Role::MANAGEMENT->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(Role::MANAGEMENT, $ordinary->fresh()->role);

        $this->get(EditUser::getUrl(['record' => $management]))->assertSuccessful();
        Livewire::test(EditUser::class, ['record' => $management->id])->assertSuccessful();
        $this->assertTrue(UserResource::canEdit($management));
        $this->assertTrue(UserResource::canDelete($management));
        $this->assertFalse(UserResource::canDeleteAny());
    }

    public function test_management_can_create_and_edit_users_with_its_users_grant(): void
    {
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::USERS, ['view', 'create', 'edit']);
        $actor = User::factory()->role(Role::MANAGEMENT)->create();
        $created = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($actor);

        $this->assertTrue(UserResource::canCreate());
        $this->get(CreateUser::getUrl())->assertSuccessful();
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Rider Baru',
            'phone_e164' => '081298760098',
            'role' => Role::STAFF->value,
            'password' => 'rahasia123',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['phone_e164' => '081298760098', 'role' => Role::STAFF->value]);

        Livewire::test(EditUser::class, ['record' => $created->id])
            ->fillForm(['name' => 'Management Uji Diubah'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Management Uji Diubah', $created->fresh()->name);
    }

    public function test_migration_up_down_up_preserves_legacy_plaintext_and_nulls(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.employee_privacy_roundtrip', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('employee_privacy_roundtrip');

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('national_id', 32)->nullable();
                $table->string('bank_account_number', 64)->nullable();
            });
            DB::table('users')->insert([
                ['id' => 1, 'national_id' => '3171234567890001', 'bank_account_number' => '7001234567'],
                ['id' => 2, 'national_id' => null, 'bank_account_number' => null],
                ['id' => 3, 'national_id' => '', 'bank_account_number' => ''],
            ]);

            $migration = require database_path('migrations/2026_10_15_000000_encrypt_employee_identifiers.php');
            $migration->up();
            $encrypted = DB::table('users')->where('id', 1)->first();
            $this->assertNotSame('3171234567890001', $encrypted->national_id);
            $this->assertNotSame('7001234567', $encrypted->bank_account_number);
            $this->assertNull(DB::table('users')->where('id', 2)->value('national_id'));
            $this->assertNull(DB::table('users')->where('id', 3)->value('national_id'));

            $migration->down();
            $plaintext = DB::table('users')->where('id', 1)->first();
            $this->assertSame('3171234567890001', $plaintext->national_id);
            $this->assertSame('7001234567', $plaintext->bank_account_number);

            $migration->up();
            $encryptedAgain = DB::table('users')->where('id', 1)->first();
            $this->assertSame('3171234567890001', Crypt::decryptString($encryptedAgain->national_id));
            $this->assertSame('7001234567', Crypt::decryptString($encryptedAgain->bank_account_number));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::disconnect('employee_privacy_roundtrip');
        }
    }

    public function test_sensitive_changes_are_audited_with_masked_values(): void
    {
        $actor = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($actor);
        $employee = User::factory()->create([
            'national_id' => '3171234567890001',
            'bank_name' => 'Bank Uji Lama',
            'bank_account_number' => '7001234567',
            'bank_account_holder' => 'Pemilik Uji Lama',
        ]);

        $employee->update([
            'national_id' => '3171234567890002',
            'bank_name' => 'Bank Uji Baru',
            'bank_account_number' => '7001234568',
            'bank_account_holder' => 'Pemilik Uji Baru',
        ]);

        $log = AuditLog::query()->where('subject_type', User::class)
            ->where('subject_id', $employee->id)->where('action', 'employee_sensitive_updated')->firstOrFail();
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('****0001', $log->before_json['national_id']);
        $this->assertSame('****0002', $log->after_json['national_id']);
        $this->assertSame('****4567', $log->before_json['bank_account_number']);
        $this->assertSame('****4568', $log->after_json['bank_account_number']);
        $this->assertArrayHasKey('bank_name', $log->after_json);
        $this->assertArrayHasKey('bank_account_holder', $log->after_json);
        $this->assertStringNotContainsString('3171234567890002', json_encode($log->after_json));
        $this->assertStringNotContainsString('7001234568', json_encode($log->after_json));
        $this->assertStringNotContainsString('Pemilik Uji Baru', json_encode($log->after_json));
    }

    public function test_cms_sensitive_change_rolls_back_when_audit_insert_fails(): void
    {
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::USERS, ['view', 'edit']);
        $actor = User::factory()->role(Role::MANAGEMENT)->create();
        $employee = User::factory()->create(['national_id' => '3171234567890001']);
        $this->actingAs($actor);

        Event::listen('eloquent.creating: '.AuditLog::class, static function (): void {
            throw new RuntimeException('audit unavailable');
        });

        try {
            Livewire::test(EditUser::class, ['record' => $employee->id])
                ->fillForm(['national_id' => '3171234567890002'])
                ->call('save');
            $this->fail('The audit insert should have stopped the save.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertSame('3171234567890001', $employee->fresh()->national_id);
    }

    public function test_management_and_administrator_see_full_values_and_can_change_them(): void
    {
        $employee = User::factory()->create([
            'name' => 'Rider Uji Privasi',
            'national_id' => '3171234567890001',
            'bank_name' => 'Bank Uji',
            'bank_account_number' => '7001234567',
            'bank_account_holder' => 'Pemilik Uji',
        ]);

        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);
        $this->get(ListUsers::getUrl())->assertSuccessful()->assertSee('3171234567890001')->assertSee('7001234567');
        $this->get(EditUser::getUrl(['record' => $employee]))->assertSuccessful()
            ->assertSee('3171234567890001')->assertSee('7001234567');
        Livewire::test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['bank_name' => 'Bank Admin'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Bank Admin', $employee->fresh()->bank_name);
        $employee->refresh()->update(['bank_name' => 'Bank Uji']);

        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        foreach ([Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            PermissionMatrix::set($role, PanelModule::USERS, ['view', 'edit']);
            $viewer = User::factory()->role($role)->create();
            $this->actingAs($viewer);
            $this->assertFalse(PermissionMatrix::can($viewer, PanelModule::PAYROLL, 'edit'), $role->value);

            $this->get(ListUsers::getUrl())->assertSuccessful()
                ->assertSee('****0001')->assertSee('****4567')
                ->assertDontSee('3171234567890001')->assertDontSee('7001234567');
            $this->get(EditUser::getUrl(['record' => $employee]))->assertSuccessful()
                ->assertSee('****0001')->assertSee('****4567')
                ->assertDontSee('3171234567890001')->assertDontSee('7001234567');

            Livewire::test(EditUser::class, ['record' => $employee->id])
                ->fillForm(['national_id' => '1111222233334444', 'bank_name' => 'Bank Bocor', 'bank_account_number' => '999988887777', 'bank_account_holder' => 'Pemilik Bocor'])
                ->call('save');
            $this->assertSame('3171234567890001', $employee->fresh()->national_id);
            $this->assertSame('7001234567', $employee->fresh()->bank_account_number);
            $this->assertSame('Bank Uji', $employee->fresh()->bank_name);
            $this->assertSame('Pemilik Uji', $employee->fresh()->bank_account_holder);
        }

        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::USERS, ['view', 'edit']);
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($management);
        $this->get(ListUsers::getUrl())->assertSuccessful()
            ->assertSee('3171234567890001')->assertSee('7001234567');
        $this->get(EditUser::getUrl(['record' => $employee]))->assertSuccessful()
            ->assertSee('3171234567890001')->assertSee('7001234567');

        Livewire::test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['national_id' => '3171234567890002', 'bank_account_number' => '7001234568'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('3171234567890002', $employee->fresh()->national_id);
        $this->assertSame('7001234568', $employee->fresh()->bank_account_number);

        $log = AuditLog::query()->where('subject_type', User::class)
            ->where('subject_id', $employee->id)->where('action', 'employee_sensitive_updated')->latest('id')->firstOrFail();
        $this->assertSame($management->id, $log->actor_id);
        $this->assertSame('****0002', $log->after_json['national_id']);
        $this->assertSame('****4568', $log->after_json['bank_account_number']);
        $this->assertStringNotContainsString('3171234567890002', json_encode($log->after_json));
        $this->assertStringNotContainsString('7001234568', json_encode($log->after_json));

        Livewire::test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['national_id' => '', 'bank_account_number' => ''])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull(DB::table('users')->where('id', $employee->id)->value('national_id'));
        $this->assertNull(DB::table('users')->where('id', $employee->id)->value('bank_account_number'));
    }

    public function test_a_role_without_users_permission_gets_403(): void
    {
        $employee = User::factory()->create();
        $this->actingAs(User::factory()->role(Role::STAFF)->create())
            ->get(EditUser::getUrl(['record' => $employee]))->assertForbidden();
    }
}
