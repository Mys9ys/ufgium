# UFGium

Приложение под симбиозные игры **«Департамент странных синих дел»** (DSSD).  
Квиз/настолка с электронным гроссбухом: VK Mini App + браузер.

## Сейчас
Локальный MVP крутится на **http://127.0.0.1:8088/** (PHP 8.3 + MariaDB `ufgium`).

Домен `ufgium.loc` в Apache OSPanel ещё не подхвачен: в панели включи проект (лежит `.osp/project.ini`) и примени конфиг.

## Документы
- [`docs/01-ufg1-rules.md`](docs/01-ufg1-rules.md) — сводка правил
- [`docs/10-round-state-machine.md`](docs/10-round-state-machine.md) — фазы тура
- [`docs/12-rules-master-table.md`](docs/12-rules-master-table.md) — таблица правил (можно дописывать)
- [`docs/13-architecture.md`](docs/13-architecture.md) — архитектура
- [`docs/14-mvp.md`](docs/14-mvp.md) — срез MVP
- [`data/rules.json`](data/rules.json) — каталог правил для кода

## Референсы
`refs/ufg1/` — реквизит, застолье, Excel первой партии.
