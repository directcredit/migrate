#!/usr/local/bin/php
<?php

/**
 * Migrate
 *
 * @author <masterklavi@gmail.com>
 * @version 0.3.1
 */

declare(strict_types=1);

error_reporting(-1);
ini_set('display_errors', 'On');

(new class() {

    const VERSION = '0.3.1';

    const VERSION_TABLE     = '_migrate_version';
    const HISTORY_TABLE     = '_migrate_history';

    const VERSION_NULL      = 'null';
    const VERSION_NULL_CODE = 'null';

    const VERSION_INIT      = '000000T000000';
    const VERSION_INIT_CODE = 'init';

    const VERSION_DEV       = '991231T235959';
    const VERSION_DEV_CODE  = 'dev';

    /**
     * @var array
     */
    protected $config;

    /**
     * @var bool
     */
    protected $includeDev = false;

    /**
     * @var PDO
     */
    protected $connection;

    /**
     * @var array
     */
    protected $migrations;

    /**
     * Loads configuration and checks environment
     */
    public function __construct()
    {
        if (PHP_SAPI !== 'cli') {
            echo 'cli sapi only supported!', PHP_EOL;
            exit(1);
        }

        if (posix_getuid() === 0) {
            echo 'root mode is disabled', PHP_EOL;
            exit(1);
        }

        $this->config = $this->getConfig('config.json');
    }

    /**
     * Handles main action
     *
     * @param int $argc
     * @param array $argv
     * @return void
     */
    public function main(int $argc, array $argv)
    {
        // check flags

        if (getenv('APP_ENV') === 'dev') {
            $this->includeDev = true;
        }

        foreach ($argv as $k => $v) {
            if ($v === '--dev') {
                unset($argv[$k]);
                $this->includeDev = true;
                break;
            }
        }

        $argv = array_values($argv);

        // load actions

        try {
            // load action by first argument

            if ($argc > 1) {

                $method = 'do' . $argv[1];

                if (method_exists($this, $method)) {
                    return call_user_func([$this, $method], $argc, $argv);
                }
            }

            // load default action

            return $this->doHelp();

        } catch (Exception $e) {
            echo 'Error: ', $e->getMessage(), PHP_EOL;
            exit(1);
        }
    }

    ///////////////////////// ACTIONS  /////////////////////////

    /**
     * Shows help and version
     */
    protected function doHelp()
    {
        echo
            'Migrate v' . self::VERSION . PHP_EOL,
            PHP_EOL,
            'Commands:' . PHP_EOL,
            '  migrate help            prints this help' . PHP_EOL,
            '  migrate status          shows info about current version' . PHP_EOL,
            '  migrate history         shows migration history' . PHP_EOL,
            '  migrate up              migrates project to latest version' . PHP_EOL,
            '  migrate down            migrates project to previous version' . PHP_EOL,
            '  migrate to {version}    migrates project to selected version' . PHP_EOL,
            '  migrate mark {version}  changes current version without migrations' . PHP_EOL,
            PHP_EOL
        ;
    }

    /**
     * Shows current status and version
     */
    protected function doStatus()
    {
        // retrieve available migrations

        $migrations = $this->getMigrations();

        // retrieve current version

        $version = $this->getCurrentVersion();

        // build info tree

        if ($version === self::VERSION_NULL) {
            echo '-> (' . self::VERSION_NULL_CODE . ')', PHP_EOL;
        } else {
            echo '   (' . self::VERSION_NULL_CODE . ')', PHP_EOL;
        }

        foreach ($migrations as $migrationVersion => $migration) {

            if ($migrationVersion === $version) {
                echo '-> ';
            } else {
                echo '   ';
            }

            if ($migrationVersion === self::VERSION_INIT) {
                $migration['code'] = '(' . self::VERSION_INIT_CODE . ')';

            } elseif ($migrationVersion === self::VERSION_DEV) {
                $migration['code'] = '(' . self::VERSION_DEV_CODE . ')';
            }

            echo
                $migrationVersion,
                ' ' . $migration['code'] . '',

                count($migration['parts']) > 1
                    ? ' (parts: ' . implode(', ', array_keys($migration['parts'])) . ')'
                    : '',

                PHP_EOL
            ;
        }
    }

    /**
     * Shows history of migrations
     */
    protected function doHistory()
    {
        foreach ($this->getHistory() as $entry) {
            echo
                $entry['date'] . ' ',
                $entry['from_version'] . ' ',
                '-> ',
                $entry['to_version'] . ' ',
                PHP_EOL
            ;
        }
    }

    /**
     * Upgrades database to latest version
     */
    protected function doUp()
    {
        // retrieve available migrations

        $migrations = $this->getMigrations();

        // retrieve current version

        $currentVersion = $this->getCurrentVersion();

        // fetch last version

        $lastVersion = max(array_keys($migrations)) ?: self::VERSION_NULL;

        // compare versions

        if (
            $currentVersion === $lastVersion
                || $lastVersion === self::VERSION_NULL
                || $currentVersion !== self::VERSION_NULL && strcmp($currentVersion, $lastVersion) > 0
        ) {
            echo '!! no versions are available to upgrade', PHP_EOL;
            return;
        }

        // build plan

        $plan = $this->buildPlan($currentVersion, $lastVersion);

        // process plan

        foreach ($plan as $task) {
            $this->migrateTask($task);
        }
    }

    /**
     * Downgrades database to previous version
     */
    protected function doDown()
    {
        // retrieve available migrations

        $migrations = $this->getMigrations();

        // retrieve current version

        $currentVersion = $this->getCurrentVersion();

        // compare versions

        if ($currentVersion === self::VERSION_NULL) {
            echo '!! no versions are available to downgrade', PHP_EOL;
            return;
        }

        // find previous version

        $previousVersion = null;
        $previousValue   = null;

        foreach (array_merge([self::VERSION_NULL], array_keys($migrations)) as $version) {
            if ($version === $currentVersion) {
                $previousVersion = $previousValue;
            }
            $previousValue = $version;
        }

        if ($previousVersion === null) {
            throw new Exception('cannot to find previous version');
        }

        // build plan

        $plan = $this->buildPlan($currentVersion, $previousVersion);

        // process plan

        foreach ($plan as $task) {
            $this->migrateTask($task);
        }
    }

    /**
     * Marks selected version without migrations
     */
    protected function doMark(int $argc, array $argv)
    {
        if ($argc < 3) {
            return $this->doHelp();
        }

        $version = $argv[2];

        // version aliases

        if ($version === self::VERSION_INIT_CODE) {
            $version = self::VERSION_INIT;

        } elseif ($version === self::VERSION_NULL_CODE) {
            $version = self::VERSION_NULL;

        } elseif ($version === self::VERSION_DEV_CODE) {
            $version = self::VERSION_DEV;
        }

        // retrieve available migrations

        $migrations = $this->getMigrations();

        // check

        if ($version !== self::VERSION_NULL && !isset($migrations[$version])) {
            throw new Exception('selected version is unknown');
        }

        // mark

        $this->updateVersion($version);
        echo 'ok', PHP_EOL;
    }

    /**
     * Migrates database to selected version
     */
    protected function doTo(int $argc, array $argv)
    {
        if ($argc < 3) {
            return $this->doHelp();
        }

        $version = $argv[2];

        // version aliases

        if ($version === self::VERSION_INIT_CODE) {
            $version = self::VERSION_INIT;

        } elseif ($version === self::VERSION_NULL_CODE) {
            $version = self::VERSION_NULL;

        } elseif ($version === self::VERSION_DEV_CODE) {
            $version = self::VERSION_DEV;
        }

        // retrieve available migrations

        $migrations = $this->getMigrations();

        // retrieve current version

        $currentVersion = $this->getCurrentVersion();

        // compare versions

        if ($currentVersion === $version) {
            echo '!! no versions are available to migrate', PHP_EOL;
            return;
        }

        if ($version !== self::VERSION_NULL && !isset($migrations[$version])) {
            throw new Exception('selected version is unknown');
        }

        // build plan

        $plan = $this->buildPlan($currentVersion, $version);

        // process plan

        foreach ($plan as $task) {
            $this->migrateTask($task);
        }
    }

    ///////////////////////// HELPERS  /////////////////////////

    /**
     * Fetches configuration by filename
     *
     * @param string $filename
     * @return array
     * @throws Exception
     */
    protected function getConfig(string $filename): array
    {
        if (!is_readable($filename)) {
            throw new Exception('config was not readable');
        }

        return json_decode(file_get_contents($filename), true);
    }

    /**
     * Validates sql file
     * @param string $filename
     * @return bool
     */
    protected function validateSqlFile(string $filename): bool
    {
        return is_readable($filename) && filesize($filename) > 32;
    }

    /**
     * Fetches list of project migrations
     *
     * @return array
     * @throws Exception
     */
    protected function getMigrations(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $this->migrations = [];

        foreach (scandir('.', SCANDIR_SORT_ASCENDING) as $migrationDir) {

            // skip files and hidden dirs

            if (!is_dir($migrationDir) || $migrationDir{0} === '.') {
                continue;
            }

            // parse version and code

            list($migrationVersion, $migrationCode) = explode('-', $migrationDir, 2);

            // skip devs

            if ($this->includeDev === false && $migrationVersion === self::VERSION_DEV) {
                continue;
            }

            // collect

            $this->migrations[$migrationVersion] = [
                'code'  => $migrationCode,
                'dir'   => $migrationDir,
                'parts' => $this->getMigrationParts($migrationVersion, $migrationDir),
            ];
        }

        return $this->migrations;
    }

    /**
     * Fetches parts on given migration
     *
     * @param string $migrationVersion
     * @param string $migrationDir
     * @return array
     * @throws Exception
     */
    protected function getMigrationParts(string $migrationVersion, string $migrationDir): array
    {
        $parts = [];

        if ($migrationVersion === self::VERSION_INIT) {
            if (!$this->validateSqlFile($migrationDir . '/up.sql')) {
                throw new Exception('invalid init migration: up.sql');
            }

            if (!$this->validateSqlFile($migrationDir . '/down.sql')) {
                throw new Exception('invalid init migration: down.sql');
            }

            $parts[1] = ['up' => 'up.sql', 'down' => 'down.sql'];

        } else {
            if (!$this->validateSqlFile($migrationDir . '/state.sql')) {
                throw new Exception('invalid migration: state.sql at ' . $migrationDir);
            }

            if (count(glob($migrationDir . '/*.up.sql')) - count(glob($migrationDir . '/*.down.sql')) !== 0) {
                throw new Exception('invalid migration: different up/down counts at ' . $migrationDir);
            }

            foreach (glob($migrationDir . '/*.up.sql') as $upFile) {
                $num = substr(basename($upFile), 0, -strlen('.up.sql'));
                $downFile = $migrationDir . '/' . $num . '.down.sql';

                if (!file_exists($downFile)) {
                    throw new Exception('invalid migration: different up/down at ' . $migrationDir);
                }

                if (!$this->validateSqlFile($upFile)) {
                    throw new Exception('invalid migration: up.sql for ' . $num . ' at ' . $migrationDir);
                }

                if (!$this->validateSqlFile($downFile)) {
                    throw new Exception('invalid migration: down.sql for ' . $num . ' at ' . $migrationDir);
                }

                $parts[$num] = ['up' => $num . '.up.sql', 'down' => $num . '.down.sql'];
            }
        }

        return $parts;
    }

    /**
     * Connects to db and returns a connection
     *
     * @param bool $cache use cache
     * @return PDO
     */
    protected function getConnection(bool $cache = true): PDO
    {
        if ($cache === false && $this->connection !== null) {
            $this->connection = null;
        }

        if ($this->connection == null) {
            $this->connection = new PDO($this->config['dsn']);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->connection;
    }

    /**
     * Fetches rows by sql
     *
     * @param string $sql
     * @return array
     * @throws Exception
     */
    protected function fetch(string $sql): array
    {
        for ($i = 1; $i <= 3; $i++) {
            try {
                $connection = $this->getConnection($i === 1);

                $stmt = $connection->query($sql);

                if ($stmt->rowCount() === 0) {
                    return [];
                }

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt->closeCursor();
                $connection = null;

                return $rows;

            } catch (PDOException $e) {
                if ($i < 3) {
                    echo
                        '!! pdo exception: ' . $e->getMessage(), PHP_EOL,
                        'refetching in 3s ...' . PHP_EOL
                    ;
                    sleep(3);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Fetches current version
     *
     * @return string
     */
    protected function getCurrentVersion(): string
    {
        if (!$this->fetch("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = '" . self::VERSION_TABLE . "'")) {
            return self::VERSION_NULL;
        }

        $rows = $this->fetch("SELECT version FROM " . self::VERSION_TABLE);

        if (!$rows) {
            return self::VERSION_NULL;
        }

        $migrations = $this->getMigrations();
        $version    = current(current($rows));

        if (!isset($migrations[$version])) {
            throw new Exception('current version is unknown');
        }

        return $version;
    }

    /**
     * Fetches history
     *
     * @return array
     */
    protected function getHistory(): array
    {
        if (!$this->fetch("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = '" . self::HISTORY_TABLE . "'")) {
            return [];
        }

        return $this->fetch("SELECT from_version, to_version, date FROM " . self::HISTORY_TABLE . " ORDER BY date DESC LIMIT 20");
    }

    /**
     * Builds plan to making migrations
     *
     * @param string $fromVersion
     * @param string $toVersion
     * @return array
     */
    protected function buildPlan(string $fromVersion, string $toVersion): array
    {
        $plan = [];

        if ($fromVersion === $toVersion) {
            return $plan;
        }

        // detect direction

        if ($fromVersion === self::VERSION_NULL) {
            $direction = 1;

        } elseif ($toVersion === self::VERSION_NULL) {
            $direction = -1;

        } else {
            $direction = strcmp($toVersion, $fromVersion);
        }

        // fetch migrations

        $migrations = $this->getMigrations();

        // make plan to upgrade

        if ($direction > 0) {
            $prevVersion = self::VERSION_NULL;

            foreach ($migrations as $migrationVersion => $migration) {

                // skip all migrations <= fromVersion
                if ($fromVersion !== self::VERSION_NULL && strcmp($fromVersion, $migrationVersion) >= 0) {
                    continue;
                }

                // skip all migrations >  toVersion
                if (strcmp($toVersion, $migrationVersion) < 0) {
                    continue;
                }

                array_push($plan, [
                    'version'   => $migrationVersion,
                    'migration' => $migration,
                    'method'    => 'up',
                    'from'      => $prevVersion,
                    'to'        => $migrationVersion,
                ]);

                $prevVersion = $migrationVersion;
            }

        } else {
            $nextVersion = self::VERSION_NULL;

            foreach ($migrations as $migrationVersion => $migration) {

                // skip all migrations >  fromVersion
                if (strcmp($fromVersion, $migrationVersion) < 0) {
                    continue;
                }

                // skip all migrations <= toVersion
                if ($toVersion !== self::VERSION_NULL && strcmp($toVersion, $migrationVersion) >= 0) {
                    $nextVersion = $migrationVersion;
                    continue;
                }

                array_unshift($plan, [
                    'version'   => $migrationVersion,
                    'migration' => $migration,
                    'method'    => 'down',
                    'from'      => $migrationVersion,
                    'to'        => $nextVersion,
                ]);

                $nextVersion = $migrationVersion;
            }
        }

        return $plan;
    }

    /**
     * Migrates init task from plan
     *
     * @param array $task
     * @throws Exception
     */
    protected function migrateInitTask(array $task)
    {
        // отображаем заголовок

        echo
            $task['method'] . ' ',
            $task['version'] . ' ',
            $task['migration']['code'] . ':',
            PHP_EOL
        ;

        // получаем sql

        $sqlPath = $task['migration']['dir'] . '/' . $task['migration']['parts'][1][ $task['method'] ];
        $sqlData = file_get_contents($sqlPath);

        if (!$sqlData) {
            throw new Exception('invalid sql: ' . $sqlPath);
        }

        // заливаем sql в транзакции

        for ($attempt = 1; $attempt <= 5; ++$attempt) {

            echo "- attempt #{$attempt}: applying ... ";

            $connection = null;

            try {
                $connection = $this->getConnection($attempt === 1);
                $connection->beginTransaction();
                $connection->exec($sqlData);
                $connection->commit();
                $connection = null;

                echo "ok", PHP_EOL;

            } catch (PDOException $e) {

                echo
                    '!! pdo exception: ' . $e->getMessage(), PHP_EOL,
                    'retrying in 10s ...' . PHP_EOL
                ;

                if ($connection !== null) {
                    try {
                        $connection->rollBack();
                    } catch (PDOException $e) {}
                    $connection = null;
                }

                sleep(10);
                continue;
            }

            // отмечаем новую версию

            $this->updateVersion($task['to']);
            $this->updateHistory($task['from'], $task['to']);
            return;
        }

        throw new Exception('no attempts');
    }

    /**
     * Migrates task from plan
     *
     * @param array $task
     * @throws Exception
     */
    protected function migrateTask(array $task)
    {
        // миграцию init делаем иначе

        if ($task['version'] === self::VERSION_INIT) {
            return $this->migrateInitTask($task);
        }

        // отображаем заголовок

        echo
            $task['method'] . ' ',
            $task['version'] . ' ',
            $task['migration']['code'] . ':',
            PHP_EOL
        ;

        // проверяем начальное состояние

        $maxState = max(array_keys($task['migration']['parts']));

        echo '- checking init state ... ';

        $state = $this->getState($task);

        if ($task['method'] === 'up' && $state === 0 || $task['method'] === 'down' && $state === $maxState) {
            echo 'ok', PHP_EOL;

        } else {
            echo "!! not an init state, ";
            if (readline('continue (y/n)? ') !== 'y') {
                throw new Exception('aborted on error');
            }
        }

        // начинаем цикл попыток: получение версии, сбор частей, выполнение частей

        $attempts = 3 + count($task['migration']['parts']);

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {

            // получаем состояние

            $state = $this->getState($task);

            if ($state < 0 || $state > $maxState) {
                throw new Exception('incorrect state: ' . $state);
            }

            // завершаем работу, если состояние финишное

            if ($task['method'] === 'up' && $state === $maxState || $task['method'] === 'down' && $state === 0) {
                break;
            }

            // определяем очередную часть на миграцию

            if ($task['method'] === 'up') {
                $part = $state + 1;
            } else {
                $part = $state;
            }

            // начинаем выполнение очередной части

            echo "- attempt #{$attempt}, state #{$state}: {$task['method']} part #{$part} ... ";

            // получаем sql

            $sqlPath = $task['migration']['dir'] . '/' . $task['migration']['parts'][$part][ $task['method'] ];
            $sqlData = file_get_contents($sqlPath);

            if (!$sqlData) {
                throw new Exception('invalid sql: ' . $sqlPath);
            }

            // заливаем sql в транзакции

            $connection = null;

            try {
                $connection = $this->getConnection($attempt === 1);
                $connection->beginTransaction();
                $connection->exec($sqlData);
                $connection->commit();
                $connection = null;

                echo "ok", PHP_EOL;

            } catch (PDOException $e) {

                echo
                    '!! pdo exception: ' . $e->getMessage(), PHP_EOL,
                    'retrying in 10s ...' . PHP_EOL
                ;

                if ($connection !== null) {
                    try {
                        $connection->rollBack();
                    } catch (PDOException $e) {}
                    $connection = null;
                }

                sleep(10);
                continue;
            }
        }

        // проверяем конечное состояние

        echo '- checking finish state ... ';

        $state = $this->getState($task);

        if ($task['method'] === 'up' && $state === $maxState || $task['method'] === 'down' && $state === 0) {
            echo 'ok', PHP_EOL;

        } else {
            throw new Exception('invalid finish state');
        }

        // отмечаем новую версию

        $this->updateVersion($task['to']);
        $this->updateHistory($task['from'], $task['to']);
    }

    /**
     * Fetches current state for task
     *
     * @param array $task
     * @return int
     * @throws Exception
     */
    protected function getState(array $task): int
    {
        $filename = $task['migration']['dir'] . '/state.sql';
        $stateSql = file_get_contents($filename);

        if (!$stateSql) {
            throw new Exception('invalid state sql: ' . $filename);
        }

        $rows = $this->fetch($stateSql);

        if (count($rows) !== 1) {
            throw new Exception('invalid state result rows');
        }

        $state = current(current($rows));

        if (!is_numeric($state)) {
            throw new Exception('invalid state: ' . $state);
        }

        $state = (int) $state;

        if ($state === -1) {
            throw new Exception('unknown state');
        }

        return $state;
    }

    /**
     * Updates current version
     *
     * @param string $version
     * @throws Exception
     */
    protected function updateVersion(string $version)
    {
        for ($i = 1; $i <= 3; ++$i) {
            $connection = null;

            try {
                $connection = $this->getConnection($i === 1);

                $stmt = $connection->query("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = '" . self::VERSION_TABLE . "'");

                if ($stmt->rowCount() === 0) {
                    $connection->exec("create table " . self::VERSION_TABLE . " (version char(13) not null, primary key (version))");
                }

                $connection->beginTransaction();
                $connection->exec('delete from ' . self::VERSION_TABLE);

                if ($version !== self::VERSION_NULL) {
                    $connection->exec("insert into " . self::VERSION_TABLE . " values (" . $connection->quote($version) . ")");
                }

                $connection->commit();
                $connection = null;
                break;

            } catch (PDOException $e) {

                echo
                    '!! pdo exception: ' . $e->getMessage(), PHP_EOL,
                    'retrying in 10s ...' . PHP_EOL
                ;

                if ($connection !== null) {
                    try {
                        $connection->rollBack();
                    } catch (PDOException $e) {}
                    $connection = null;
                }

                sleep(10);
                continue;
            }
        }
    }

    /**
     * Updates current version
     *
     * @param string $version
     * @throws Exception
     */
    protected function updateHistory(string $versionFrom, string $versionTo)
    {
        for ($i = 1; $i <= 3; ++$i) {
            $connection = null;

            try {
                $connection = $this->getConnection($i === 1);

                $stmt = $connection->query("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = '" . self::HISTORY_TABLE . "'");

                if ($stmt->rowCount() === 0) {
                    $connection->exec(
                        "create table " . self::HISTORY_TABLE . " ("
                            . "date timestamp not null, "
                            . "from_version char(13) not null, "
                            . "to_version char(13) not null, "
                            . "primary key (date))"
                    );
                }

                $connection->exec(
                    "insert into " . self::HISTORY_TABLE . " (from_version, to_version, date) values ("
                        . $connection->quote($versionFrom) . ","
                        . $connection->quote($versionTo) . ","
                        . "now()"
                        . ")"
                );

                $connection = null;
                return;

            } catch (PDOException $e) {

                echo
                    '!! pdo exception: ' . $e->getMessage(), PHP_EOL,
                    'retrying in 10s ...' . PHP_EOL
                ;

                if ($connection !== null) {
                    try {
                        $connection->rollBack();
                    } catch (PDOException $e) {}
                    $connection = null;
                }

                sleep(10);
                continue;
            }
        }

        echo "!! no attempts", PHP_EOL;
    }

})->main($argc, $argv);
