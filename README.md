
# Migrate

Инструмент для выполнения версионируемых миграций.

Скрипт считывает имеющиеся миграции в локальной директории, подключается к удаленной СУБД, определяя текущую версию,
проверяет консистентность, производит накат одной или нескольких миграций.  


## Системные требования

- php версии 7.3 и выше
- расширение php pdo

## Установка

```
# cp migrate.php /usr/local/bin/migrate
```

## Команды

```
$ migrate
Migrate v0.3

Commands:
  migrate help            prints this help
  migrate status          shows info about current version
  migrate history         shows migration history
  migrate up              migrates project to latest version
  migrate down            migrates project to previous version
  migrate to {version}    migrates project to selected version
  migrate mark {version}  changes current version without migrations
```

### status

Отображает состояние проекта и текущую версию базы данных.

### history

Отображает состояние проекта и текущую версию базы данных.

### up

Выполняет накат миграций от текущей до последней версии.

### down

Выполняет откат последней миграции.

### to {version}

Выполняет накат либо откат до указанной версии

### mark

Выполняет маркировку текущей версии без выполнения миграций.  
Данная команда может использоваться при возникновении внештатных ситуациях, при которых данные не консистентны 
относительно миграций.


## Структура проекта миграций

общий формат:

- {migration version}-{migration-code}
    - {submigration no}.up.sql
    - {submigration no}.down.sql
    - state.sql
- ...
- config.json

пример:

- 000000T000000-init
    - up.sql
    - down.sql
- 190515T164000-mailcontext
    - 1.up.sql
    - 1.down.sql
    - 2.up.sql
    - 2.down.sql
    - state.sql
- config.json

## Специальные версии

Первичное состояние, когда не применены никакие миграции, обозначается нулевой версией или null.  
Базовое состояние, в котором применена инициализирующая миграция, обозначается как 000000T000000-init или просто init.  

В целях тестирование, может использоваться последняя версия 991231T235959-dev (или просто dev), которая становится видимй скрипту
при включении опции --dev при выполнении команд.  
Внимание! Следует быть осторожным с dev версией, поскольку при выключении опции dev, команды могут выполняться некорректно.  

## Специальные таблицы

Для хранения текущей версии и истории применения миграций,
используются специальные таблицы _migrate_version и _migrate_history.

## Формат конфигурации

Конфигурация проекта хранится в файле config.json в ввиде:
```
{
    "version": "0.3",
    "dsn": "pgsql:host=192.168.0.1;port=5432;dbname=db1;user=user1"
}
```
