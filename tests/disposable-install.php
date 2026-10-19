<?php

use Flarum\Foundation\Paths;
use Flarum\Install\AdminUser;
use Flarum\Install\BaseUrl;
use Flarum\Install\DatabaseConfig;
use Flarum\Install\Installation;
use Flarum\Install\Steps\ConnectToDatabase;
use Flarum\Testing\integration\UsesTmpDir;

require __DIR__.'/../vendor/autoload.php';

$database = getenv('DB_DATABASE') ?: '';
if ($database !== 'flarum_public_counts_r4') {
    fwrite(STDERR, "Refusing to install: database must be the disposable flarum_public_counts_r4 schema.\n");
    exit(1);
}

$runner = new class {
    use UsesTmpDir;

    public function run(): void
    {
        $config = new DatabaseConfig(
            'mysql',
            getenv('DB_HOST') ?: 'localhost',
            (int) (getenv('DB_PORT') ?: 3306),
            'flarum_public_counts_r4',
            getenv('DB_USERNAME') ?: '',
            getenv('DB_PASSWORD') ?: '',
            ''
        );
        (new ConnectToDatabase($config, function ($db) {
            $builder = $db->getSchemaBuilder();
            $builder->dropAllTables();
            $builder->dropAllViews();
        }))->run();

        $tmp = $this->tmpDir();
        $this->setupTmpDir();
        $pipeline = (new Installation(new Paths([
            'base' => $tmp,
            'public' => $tmp.'/public',
            'storage' => $tmp.'/storage',
            'vendor' => getcwd().'/vendor',
        ])))
            ->configPath('config.php')
            ->debugMode(true)
            ->baseUrl(BaseUrl::fromString('http://localhost'))
            ->databaseConfig($config)
            ->adminUser(new AdminUser('admin', 'password', 'admin@machine.local'))
            ->settings(['mail_driver' => 'log'])
            ->extensions([])
            ->build();
        $pipeline->run();
        fwrite(STDOUT, "disposable install complete\n");
    }
};

$runner->run();
