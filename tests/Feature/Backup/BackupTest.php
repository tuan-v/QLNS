<?php

namespace Tests\Feature\Backup;

use App\Jobs\RunBackup;
use App\Models\User;
use App\Services\Backup\MysqlDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

// Sao lưu dữ liệu (2026-10-23). Test chạy SQLite nên thay MysqlDumper bằng bản giả ghi vài câu SQL; mọi file ghi vào
// thư mục tạm riêng của test.
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->root = sys_get_temp_dir().'/qlns-backup-test-'.uniqid();
        File::ensureDirectoryExists("{$this->root}/uploads/contracts");
        file_put_contents("{$this->root}/uploads/contracts/hd-001.pdf", 'noi dung hop dong');
        config([
            'backup.path' => "{$this->root}/backups",
            'backup.keep' => 2,
            'backup.sources' => ['private' => "{$this->root}/uploads"],
        ]);
        $this->fakeDumper("CREATE TABLE zz_backup_check (id integer, note varchar(50));\nINSERT INTO zz_backup_check VALUES (1, 'a;b');\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function fakeDumper(?string $sql): void
    {
        $this->app->instance(MysqlDumper::class, new class ($sql) extends MysqlDumper {
            public function __construct(private readonly ?string $sql)
            {
            }

            public function dump(string $path): void
            {
                if ($this->sql === null) {
                    throw new RuntimeException('Mất kết nối CSDL');
                }
                file_put_contents($path, $this->sql);
            }
        });
    }

    private function headers(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function backupNames(): array
    {
        return collect(File::files(config('backup.path')))->map->getFilename()->sort()->values()->all();
    }

    public function test_backup_command_zips_database_and_uploaded_files(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        [$name] = $this->backupNames();
        $this->assertMatchesRegularExpression('/^qlns-backup-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $name);

        $zip = new ZipArchive();
        $zip->open(config('backup.path').'/'.$name);
        $this->assertStringContainsString('zz_backup_check', $zip->getFromName('database.sql'));
        $this->assertSame('noi dung hop dong', $zip->getFromName('files/private/contracts/hd-001.pdf'));
        $this->assertSame(1, json_decode($zip->getFromName('manifest.json'), true)['files']);
        $zip->close();

        $this->assertDatabaseHas('audit_logs', ['action' => 'backup_created', 'auditable_type' => 'backup', 'user_id' => null]);
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        File::ensureDirectoryExists(config('backup.path'));
        foreach (['2026-01-01_020000', '2026-01-02_020000', '2026-01-03_020000'] as $stamp) {
            touch(config('backup.path')."/qlns-backup-{$stamp}.zip");
        }
        touch(config('backup.path').'/ghi-chu-khac.txt');

        $this->artisan('backup:run')->assertSuccessful();

        $names = $this->backupNames();
        $this->assertCount(3, $names); // 2 bản mới nhất + file lạ không bị đụng tới
        $this->assertContains('qlns-backup-2026-01-03_020000.zip', $names);
        $this->assertContains('ghi-chu-khac.txt', $names);
        $this->assertNotContains('qlns-backup-2026-01-02_020000.zip', $names);
    }

    public function test_failed_backup_notifies_admins_and_leaves_no_partial_file(): void
    {
        $this->fakeDumper(null);
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        // Lỗi phải được ghi log — chặn lại ở đây để test không ghi lỗi giả vào storage/logs thật.
        Log::shouldReceive('error')->once();

        $this->artisan('backup:run')->assertFailed();

        $this->assertSame([], $this->backupNames());
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'backup.failed']);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => User::where('email', 'hr@qlns.local')->value('id'),
            'type' => 'backup.failed',
        ]);
    }

    public function test_only_backup_permission_can_use_the_api(): void
    {
        $this->getJson('/api/v1/backups', $this->headers('hr@qlns.local', 'Hr@123456'))->assertForbidden();
        $this->postJson('/api/v1/backups', [], $this->headers('employee@qlns.local', 'Employee@123'))->assertForbidden();
    }

    public function test_admin_runs_backup_in_background_and_is_notified(): void
    {
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $headers = $this->headers('admin@qlns.local', 'Admin@123');

        Queue::fake();
        $this->postJson('/api/v1/backups', [], $headers)->assertStatus(202);
        Queue::assertPushed(RunBackup::class, fn (RunBackup $job) => $job->requestedBy === $admin->id);

        (new RunBackup($admin->id))->handle(app(\App\Services\BackupService::class));

        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'backup.completed']);
        $this->getJson('/api/v1/backups', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('schedule.keep', 2);
    }

    public function test_admin_downloads_a_backup_but_cannot_reach_other_files(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        [$name] = $this->backupNames();
        $headers = $this->headers('admin@qlns.local', 'Admin@123');

        $this->get("/api/v1/backups/{$name}/download", $headers)->assertOk()->assertDownload($name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup_downloaded', 'auditable_type' => 'backup']);

        $this->getJson('/api/v1/backups/'.urlencode('../.env').'/download', $headers)->assertNotFound();
        $this->getJson('/api/v1/backups/qlns-backup-2000-01-01_000000.zip/download', $headers)->assertNotFound();
    }

    public function test_restore_command_reloads_database_and_files(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        [$name] = $this->backupNames();
        File::delete("{$this->root}/uploads/contracts/hd-001.pdf");

        $this->artisan('backup:restore', ['file' => $name, '--force' => true])->assertSuccessful();

        $this->assertSame('a;b', DB::table('zz_backup_check')->value('note'));
        $this->assertSame('noi dung hop dong', file_get_contents("{$this->root}/uploads/contracts/hd-001.pdf"));
    }
}
