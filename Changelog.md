# Changelog — os-amneziawg

---

## v3.0.0 — 2026-06-05

### Multi-instance: несколько туннелей одновременно (BACKLOG #1)

**Breaking change: миграция модели конфигурации.** Плоская модель `//OPNsense/amneziawg/instance` (один туннель) заменена на ArrayField `//OPNsense/amneziawg/instances/instance` с UUID-ключами — до 100 туннелей `awg0`–`awg99`. При обновлении с 2.x существующий туннель мигрируется автоматически (`install.sh` запускает `run_migrations.php`): генерируется UUID, `private.key` переименовывается в `<uuid>.key`, legacy-узлы удаляются. Работающий туннель при обновлении не прерывается.

**Грид туннелей вместо одиночной формы.** Вкладка Instance заменена вкладкой **Tunnels**: bootgrid-таблица (enabled-toggle, имя, номер интерфейса, endpoint) + диалог редактирования со всеми полями. Add подставляет первый свободный номер интерфейса; дубликаты номеров отклоняются валидацией; Delete удаляет и `<uuid>.key`.

**Per-row Start/Stop.** Кнопки ▶/■ в каждой строке грида управляют отдельным туннелем (`POST /api/amneziawg/service/start_instance|stop_instance/<uuid>`). Ручная остановка туннеля ставит per-instance флаг `/var/run/amneziawg_stopped_awgN.flag` — watchdog не поднимает его обратно. Сервисные операции (Start/Stop/Restart/Apply) сбрасывают per-row стопы.

**Гранулярный watchdog.** При падении туннеля перезапускается только он (`start_instance`), остальные живут без разрыва сессий. Если умер только sentinel-процесс — новое действие `sentinel_repair` чинит PID-файл, не трогая туннели.

**Per-instance диагностика.** Селектор туннеля на вкладке Diagnostics: статистика, Test Connection и Copy Debug Info работают по выбранному интерфейсу (`configctl amneziawg ifstats awgN` / `testconnect awgN`, anti-injection валидация токена).

**Импорт .conf → новый туннель.** Parse & Fill открывает Add-диалог с заполненными полями (или дозаполняет уже открытый диалог). Generate Keypair встроен в диалог: возвращает пару stateless, приватный ключ сохраняется на диск при Save.

**SEC-1 (re-scope).** Приватные ключи: один файл на туннель `/usr/local/etc/amnezia/<uuid>.key` (0600), в config.xml — sentinel `::file::`. Runtime-конфиги `awgN.conf` зачищаются при остановке сервиса — удалённый туннель не оставляет ключ на диске.

### Test Connection: подсказки при ошибке (BACKLOG #2)

При неудаче теста выполняется активная диагностика причины: `interface_down` / `handshake_stale` / `dns_failure` / `no_route` / `remote_unresponsive`. JSON-ответ получил `error_code` и `hint` — GUI показывает подсказку следующего шага под сообщением об ошибке (например, «туннель и handshake в порядке, но маршрута через awgN нет — настрой selective routing, README §4–7»).

### Исправления

**Watchdog-cron никогда не создавался.** Хук `amneziawg_cron()` возвращал запись в неверном формате (имя PHP-функции вместо shell-команды, без ключа `autocron`) — ядро OPNsense молча игнорировало её, и запись в crontab не попадала: watchdog не запускался вовсе. Теперь хук отдаёт `['autocron' => ['/usr/local/sbin/configctl -d amneziawg watchdog', '*']]` — ежеминутный запуск; сам скрипт мгновенно выходит, если флаг watchdog выключен.

**Шлюз навсегда «down» после рестарта туннеля.** `awg-quick` пересоздаёт интерфейс, при этом теряются host-route монитор-IP и состояние dpinger — назначенный на туннель шлюз с мониторингом оставался «down», и gateway group не возвращала трафик на Tier 1. Теперь `awg_up()` после успешного поднятия дёргает `configctl -d interface newip awgN` (rc.newwanip, как в core-плагине os-wireguard) — маршруты и dpinger восстанавливаются автоматически; для неназначенных интерфейсов это no-op.

**Пустой Edit-диалог грида.** `mapDataToFormUI` в OPNsense матчит форму диалога по `id.split('-')[0]` — дефис в id грида (`grid-instances`) ломал загрузку данных. Id гридов/диалогов теперь без дефисов (как в core-плагинах).

**Кнопка Add в OPNsense 26.x.** Tabulator-грид 26.x заменяет исходную таблицу — `tfoot button[data-action=add]` исчезает из DOM. Import теперь кликает `button.command-add` (с fallback на legacy-селектор для 25.x).

**Reconfigure сбрасывает stopped-флаг.** Раньше после ручного Stop + Apply туннели поднимались, но watchdog продолжал считать сервис остановленным.

**curl `000` больше не показывается как HTTP-код.** Test Connection при отсутствии ответа пишет «no response» вместо «Unexpected HTTP code 000».

### Новые API-эндпоинты и configd-действия

- `POST /api/amneziawg/instance/search_item|get_item|add_item|set_item|del_item|toggle_item` — CRUD туннелей (UUID)
- `POST /api/amneziawg/service/start_instance/<uuid>`, `stop_instance/<uuid>` — управление отдельным туннелем
- configd: `[start_instance]`, `[stop_instance]`, `[sentinel_repair]`; `[ifstats]`/`[testconnect]` принимают параметр `awgN`

---

## v2.7.0 — 2026-05-28

### Полная поддержка AmneziaWG 2.0

**S3/S4 — padding параметры AWG 2.0**
Новые поля S3 (handshake cookie padding) и S4 (transport message padding), 0–1280. Прокинуты по всей цепочке: модель → GUI → генерация конфига.

**H1–H4 — поддержка диапазонов**
Поля принимают одиночное значение (`12345`) или диапазон (`12345-67890`). Серверная валидация: формат, диапазон 5–4294967295, взаимное непересечение диапазонов H1–H4 (требование драйвера).

**I1–I5 — CPS (Custom Protocol Signature)**
Поддержка пакетов DPI-маскировки с тегами `<b 0xHEX>`, `<r N>`, `<t>`, `<c>` и др. Двойной `html_entity_decode` компенсирует HTML-эскейп Phalcon при чтении и записи конфига.

**Документация**
README переработан под AWG 2.0: системные требования (amnezia-kmod 2.0.x), описание новых параметров, troubleshooting.

---

## v2.6.0 — 2026-03-17

### Исправления

**pkg self-upgrade из FreeBSD quarterly ломает OPNsense**
При установке amnezia-kmod из FreeBSD quarterly repo, `pkg` обновлялся до несовместимой с OPNsense версии (2.3.1 → 2.5.1), после чего `pkg update` падал с segfault. Теперь `pkg` блокируется через `pkg lock` перед установкой из quarterly и разблокируется после. Добавлена автоматическая проверка и восстановление `pkg` при запуске инсталлятора, а также пост-проверка целостности.

**Несовместимость модуля ядра с версией FreeBSD**
FreeBSD quarterly может содержать amnezia-kmod, собранный для другой версии ядра (например 14.4 вместо 14.2/14.3 на OPNsense), что вызывает kernel panic и случайные ребуты. Добавлена проверка совместимости ABI через dry-run install перед установкой kmod. После установки kmod блокируется через `pkg lock` для защиты от случайного обновления.

**Зависание при загрузке если модуль ядра отсутствует/сломан**
`awg-quick up` зависал при загрузке если `if_amn` не загружен. Теперь boot hook (`50-amneziawg`), service-control.php (start/restart/reconfigure/validate) и watchdog проверяют наличие модуля ядра через `kldstat` и пытаются загрузить его через `kldload` перед запуском туннеля. При неудаче — чистый выход с понятным сообщением об ошибке вместо зависания.

**Деинсталляция: очистка заблокированных пакетов**
При `sh install.sh uninstall` теперь предлагается удалить пакеты amnezia-kmod и amnezia-tools, с автоматической разблокировкой и очисткой записи `if_amn_load` из `/boot/loader.conf`.

**Pre-check не обнаруживал уже сломанный pkg**
При обновлении с v2.5.0 пакеты уже установлены (`NEED_KMOD=0`), поэтому quarterly-логика пропускалась и `pkg lock` не вызывался. Старый pre-check через `pkg info pkg` проходил даже со сломанным pkg (segfault только при `pkg update`). Теперь pre-check дополнительно проверяет репозиторий происхождения pkg через `pkg-static query '%R' pkg` — если pkg установлен из `FreeBSD-quarterly` вместо `OPNsense`, предлагается автоматический даунгрейд. Также amnezia-kmod теперь блокируется при обновлении плагина, даже если пакеты уже были установлены ранее.

**Зависание sentinel daemon при start/restart/reconfigure**
PHP `exec()` блокировался навечно при запуске sentinel процесса (`daemon -p pidfile sleep 999999999`). Причина: `daemon(8)` форкает `sleep`, который наследует stdout pipe от PHP exec(). PHP ждёт закрытия всех writers на pipe, но sleep живёт ~31 год. Исправлено добавлением `>/dev/null 2>&1` — перенаправляет вывод в `/dev/null` до fork, поэтому sleep не наследует pipe и exec() возвращается мгновенно.

---

## v2.5.0 — 2026-03-08

### Исправления

**Dashboard: сервис показывал "Stopped" при работающем туннеле**
OPNsense Dashboard проверяет статус сервиса через PID-файл (`pgrep -nF pidfile`). Старый код записывал `getmypid()` — PID скрипта service-control.php, который завершался сразу после `awg-quick up`. Теперь при старте туннеля запускается sentinel-процесс через `daemon(8)` + `sleep 999999999` — легковесный процесс, который живёт пока туннель активен. `daemon -p` автоматически записывает PID дочернего процесса в файл. Dashboard корректно отображает Running/Stopped. Примечание: FreeBSD `sleep` не поддерживает `infinity` (GNU-расширение), используется числовое значение (~31 год).

**H1-H4: сообщение валидации отображало `&gt;=` вместо `>=`**
Символ `>=` в PHP-строке экранировался при отображении в HTML. Заменено на текстовое описание `"must be in range 5-4294967295"` без спецсимволов.

### Тесты

**PHPUnit тест-сьют (161 тест, 417 assertions)**
Структурные и security-тесты для всех слоёв плагина:
- Модели XML: маски полей, типы, диапазоны H1-H4/S1-S2/Jc/Jmin/Jmax
- Контроллеры: POST enforcement, sentinel ключа, H1-H4 cross-validation
- Скрипты: escapeshellarg, flock, stopped flag, proc_open timeout, sentinel daemon
- install.sh: shell safety, pkg install, uninstall cleanup, config detection
- configd actions: все 12 действий, отсутствие timeout field
- ACL/Menu структура
- Volt GUI: вкладки, кнопки, auto-refresh, i18n, POST для логов

---

## v2.4.1 — 2026-03-08

### Исправления

**Диагностика: корректный парсинг netstat на FreeBSD 14**
FreeBSD 14 `netstat -ibn` имеет колонки `Idrop` и `Coll`, а Link-строка содержит Address. Индексы колонок исправлены: Ipkts=4, Ibytes=7, Opkts=8, Obytes=10. Ранее показывало 0 B.

**Диагностика: uptime через mtime PID-файла**
PID-файл содержит PID давно завершившегося скрипта service-control.php, а не демона. `ps -o etime=` не мог его найти. Теперь uptime рассчитывается из mtime PID-файла (= момент старта туннеля). Формат: `2d 5h 30m` / `45m 12s`.

**Validate: awg-quick strip требует именования файла <iface>.conf**
`tempnam()` создавал файл с рандомным именем — awg-quick отвергал его. Теперь validate использует реальный путь конфига (`/usr/local/etc/amnezia/awg0.conf`), что безопасно — `strip` только читает.

---

## v2.4.0 — 2026-03-08

### Мониторинг и диагностика (MED-3, MED-4, MED-5, MED-6, MED-7)

**Вкладка Diagnostics**
Новая вкладка в GUI с таблицей статистики интерфейса: статус, IP, MTU, public key, listen port, peer endpoint, latest handshake, transfer rx/tx, netstat packets, uptime. Автообновление каждые 30 секунд.

**Test Connection**
Кнопка проверки связности через туннель — отправляет HTTP-запрос к `cp.cloudflare.com/generate_204` через интерфейс awg0 (curl --interface). Результат: Success/Failed с сообщением.

**Validate Config**
Кнопка проверки конфига без применения (dry-run). Генерирует конфиг во временный файл, проверяет через `awg-quick strip`, удаляет. Показывает результат в диалоге.

**Вкладка Log**
Просмотр последних 150 строк `/var/log/amneziawg.log` прямо в GUI. POST-only эндпоинт (лог содержит IP-адреса). Кнопка Refresh.

**Copy Debug Info**
Кнопка сбора диагностики + логов в модальное окно. Собирает diagnostics JSON + log параллельно, форматирует для копирования при обращении в поддержку.

### Watchdog (MED-3)

**amneziawg-watchdog.php**
Автоперезапуск туннеля при падении. Проверяет каждую минуту через cron (зарегистрирован в `amneziawg.inc` → `amneziawg_cron()`). Проверяет: PID alive + интерфейс awg0 существует. При обнаружении проблемы — `configdRun('amneziawg restart')`.

**Stopped flag** (`/var/run/amneziawg_stopped.flag`)
При ручном Stop устанавливается флаг — watchdog не перезапускает. При Start/Restart — флаг удаляется.

**Поле watchdog в General.xml**
Новый чекбокс «Enable Watchdog» на вкладке General. По умолчанию выключен.

### Улучшение install.sh (MED-8, MED-9)

**Определение существующего конфига**
install.sh теперь ищет `/usr/local/etc/amnezia/awg*.conf` и проверяет config.xml на наличие данных. При обнаружении конфига на диске и отсутствии в config.xml — предлагает импортировать.

**Импорт конфига**
При первой установке парсит найденный .conf файл и записывает все поля (Address, DNS, Jc/Jmin/Jmax, S1/S2, H1-H4, Peer) в config.xml через PHP OPNsense API. Приватный ключ → в private.key, sentinel в config.xml.

**Защита от перезаписи**
Проверка `CONFIG_XML_HAS_AWG` — если config.xml уже содержит настройки (peer_public_key), импорт пропускается.

### Новые скрипты и configd actions

- `amneziawg-ifstats.php` → `[ifstats]`
- `amneziawg-testconnect.php` → `[testconnect]`
- `amneziawg-watchdog.php` → `[watchdog]`
- `validate` case в service-control.php → `[validate]`
- `[log]` → tail -n 150

### Новые API-эндпоинты

- `GET /api/amneziawg/service/diagnostics` — JSON со статистикой интерфейса
- `POST /api/amneziawg/service/testconnect` — тест связности через туннель
- `POST /api/amneziawg/service/log` — последние 150 строк лога
- `POST /api/amneziawg/service/validate` — валидация конфига (dry-run)

---

## v2.3.2 — 2026-03-08

### Исправления

**H1–H4: исправлен допустимый диапазон значений (Invalid argument)**
Параметры H1–H4 (magic headers для обфускации пакетов) принимали значения от 1, но драйвер `if_amn` требует минимум 5. Значения 1–4 зарезервированы для стандартных типов пакетов WireGuard (init=1, response=2, cookie=3, data=4) и вызывали ошибку `Invalid argument` при попытке поднять туннель.

- `Instance.xml` — MinimumValue для H1–H4 изменён с 1 на 5, добавлены ValidationMessage
- `InstanceController.php` — серверная валидация: H1–H4 >= 5, значения не должны повторяться (требование драйвера)
- `amneziawg-service-control.php` — safety net при генерации конфига: невалидные H-значения пропускаются с WARNING в лог
- `forms/instance.xml` — обновлены help-тексты с указанием диапазона 5–4294967295 и требования уникальности

---

## v2.3.1 — 2026-03-06

### Документация

**Расширена секция устранения неполадок в README**
Полная переработка раздела «Устранение неполадок»: добавлен порядок диагностики (7 шагов), таблица ошибок с причинами и решениями, секции по проблемам с ключами, lock-файлом, MSS Clamping, syshook автозапуском. Добавлена таблица логов и список полезных команд включая `configctl amneziawg version`.

---

## v2.3.0 — 2026-03-06

### Информация о версии при установке

**Отображение текущей и новой версии в install.sh**
При запуске `install.sh` показывается текущая установленная версия и версия, которая будет установлена. В зависимости от ситуации:
- **Первая установка** — `Install version X? [Y/n]`
- **Обновление** — `Upgrade from X to Y? [Y/n]`
- **Переустановка** (та же версия) — `Reinstall? [y/N]` (по умолчанию отказ)

Версия сохраняется в файле `version.txt` при установке и удаляется при uninstall.

**Команда `configctl amneziawg version`**
Добавлена configd-команда для проверки установленной версии плагина. Возвращает JSON: `{"version":"2.3.0"}`.

**API-эндпоинт `GET /api/amneziawg/service/version`**
Версия плагина доступна через REST API (`ServiceController::versionAction()`).

---

## v2.2.0 — 2026-03-06

### Управление сервисом через GUI

**Кнопки Start / Stop / Restart**
Добавлены кнопки управления сервисом на вкладке Instance (паттерн os-xray). Статус-бейдж `awg: running/stopped` обновляется каждые 10 секунд. Кнопки синхронизируются со статусом (Start неактивна при running, Stop при stopped).

**Автозапуск при загрузке**
Добавлен rc.syshook скрипт `/usr/local/etc/rc.syshook.d/start/50-amneziawg`. При загрузке OPNsense проверяет `general.enabled` и наличие бинарников, затем запускает туннель автоматически.

### Исправления configd

**Убрано поле `timeout:60` из actions_amneziawg.conf**
Поле `timeout:` в определениях configd actions приводило к тому, что `$backend->configdRun()` возвращал пустую строку из PHP-FPM контекста (при этом `configctl` из терминала работал нормально). Убрано из всех 4 действий (start/stop/restart/reconfigure). Формат теперь идентичен os-xray.

**Пауза статус-поллинга во время сервисных операций**
configd сериализует запросы. Поллинг `tunnel_status` каждые N секунд мог блокировать сервисные команды. Теперь поллинг приостанавливается (`_statusPaused=true`) на время выполнения Start/Stop/Restart/Apply и возобновляется через 2 секунды после завершения. Интервал увеличен с 5 до 10 секунд.

### Защита от зависаний

**`awg_exec_timeout()` — таймаут для awg-quick**
Все вызовы `awg-quick up/down` теперь обёрнуты в `awg_exec_timeout()` (30 секунд). Используется `proc_open` с неблокирующим чтением. При превышении таймаута процесс убивается через `SIGKILL`, rc=124. Это предотвращает бесконечное зависание lock-файла при проблемах с сетью.

**Улучшенная защита flock с таймаутом 120 секунд**
- Lock-файл теперь хранит PID процесса-владельца и обновляет mtime
- `awg_pid_alive()` проверяет жив ли процесс (posix_kill с fallback на shell `kill -0`)
- Если lock старше 120 секунд — процесс-владелец убивается принудительно, даже если формально жив (зависший awg-quick)
- `register_shutdown_function()` логирует PHP fatal errors в amneziawg.log

### ServiceController

**`runAction()` — единый хелпер**
Все сервисные действия (start/stop/restart/reconfigure) используют `runAction()` — вызывает `configdRun()`, проверяет `ERROR/failed` маркеры.

**`tunnelStatusAction()`**
Отдельный GET-эндпоинт для детальной информации о туннеле (awg show). Статус-бейдж использует его для поллинга. Базовый `statusAction()` наследуется от `ApiMutableServiceControllerBase` (проверка PID-файла).

---

## v2.1.3 — 2026-03-06

### Критические исправления

**[CRIT-1] Убраны захардкоженные URL пакетов с протухающими хэшами**
Ранее `install.sh` содержал URL вида `pkg.freebsd.org/.../Hashed/amnezia-kmod-...~eed49ec054.pkg`. Хэш менялся при каждом обновлении quarterly-репозитория FreeBSD — установка ломалась. Теперь установщик:
- Проверяет наличие бинарников и модуля ядра
- Спрашивает разрешение на установку из FreeBSD quarterly (`[Y/n]`)
- Создаёт временный repo-конфиг, ставит пакеты через `pkg install -r FreeBSD-quarterly`, удаляет конфиг
- При отказе — выводит инструкции для ручной установки

**[CRIT-2] Хрупкая проверка статуса reconfigure**
`ServiceController::reconfigureAction()` использовал `$output === 'OK'` — точное сравнение ломалось при `"OK\n"` или пустом ответе (timeout configd). Заменено на робастную проверку: `strpos('OK') + отсутствие ERROR/failed`.

**[CRIT-3] genKeyPair мог вернуть пустые ключи**
`shell_exec('awg genkey')` мог вернуть null при ошибке — пустой ключ молча сохранялся. Добавлена валидация результата.

### Безопасность

**[HIGH-6] XSS-защита в ImportController**
Все значения из парсинга `.conf` файла теперь проходят через `htmlspecialchars(value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` перед возвратом в JSON. Закрывает XSS-вектор через вредоносный .conf.

**[HIGH-7] Обработка ошибок json_decode в ImportController**
Добавлена проверка `@json_decode()` + `is_array()`. При невалидном JSON тело запроса не вызывает PHP warning.

### Валидация модели

**[HIGH-2] Добавлена regex-маска для peer_allowed_ips**
Поле принимало произвольные строки. Добавлена маска `/^([0-9a-fA-F:\.]+\/\d{1,3})(,\s*[0-9a-fA-F:\.]+\/\d{1,3})*$/`.

**[HIGH-3] Добавлена regex-маска для DNS**
Поле было без валидации. Добавлена маска для списка IP-адресов.

**Версия модели обновлена с 0.0.0 до 1.0.0.**

### Улучшения install.sh

- Добавлен `set -u` (защита от неинициализированных переменных)
- Добавлена переменная `PLUGIN_VERSION` и баннер с версией
- Добавлены хелперы `warn()` / `die()` для единообразного вывода
- Uninstall теперь удаляет `/usr/local/etc/amnezia/` целиком (ранее оставались .conf файлы)

---

## v2.1.0 — 2026-02-26

### Безопасность

**[SEC-1] Приватный ключ вынесен из config.xml в защищённый файл**
Поле `private_key` больше не хранится в `/conf/config.xml`. В конфиге записывается sentinel-строка `::file::`, реальный ключ хранится в `/usr/local/etc/amnezia/private.key` (права `0600 root:root`). Ключ не попадает в конфигурационные бэкапы и не синхронизируется через xmlrpc в HA-кластерах.

**[SEC-2] Приватный ключ больше не возвращается в API**
`genKeyPairAction()` больше не отправляет `private_key` в тело ответа. Ключ записывается напрямую в защищённый файл, в браузер передаётся только `public_key`. Поле Private Key в GUI отображает bullet-плейсхолдер `••••` — ключ никогда не передаётся в браузер.

**[SEC-3] ImportController принимал GET-запросы**
Добавлена проверка `isPost()` в начале `parseAction()`. GET-запросы к `/api/amneziawg/import/parse` теперь возвращают `Method not allowed`. Закрывает вектор CSRF через прямую ссылку.

**[SEC-4] Инъекция символов переноса строки в конфиг туннеля**
Добавлена функция `awg_sanitize()`. Все значения полей перед записью в `.conf` файл проходят через `str_replace(["\n", "\r"], '', $value)`. Без этого злоумышленник мог вставить переносы строк в поля и инъектировать произвольные директивы в конфиг туннеля.

**[SEC-6] Ротация лога**
Добавлен файл `/etc/newsyslog.conf.d/amneziawg.conf`. FreeBSD `newsyslog` теперь автоматически ротирует `/var/log/amneziawg.log` при превышении 1 МБ или ежедневно — хранится 5 архивов, сжатых gzip.

### Исправления багов

**[BUG-1] `exec()` без захвата вывода в start/stop/restart/reconfigure**
Все inline-вызовы `exec(... '2>/dev/null')` без параметров `$out` и `$rc` заменены на полноценные вызовы с захватом вывода и логированием через `awg_log()`. Добавлен `escapeshellarg()` для путей к конфигу.

**[BUG-2] PID не удалялся при reconfigure с disabled инстансом**
При `reconfigure` когда `instance.enabled = 0` функция `awg_get_instances()` возвращает пустой массив. Добавлена явная проверка: если массив пустой и PID-файл существует — файл удаляется. Дашборд OPNsense больше не показывает ложный статус "running".

**[BUG-3] Мёртвый код `awg_is_up()`**
Функция была объявлена но нигде не вызывалась. Удалена.

**[BUG-4] PID не удалялся в stop-фазе `restart`**
В блоке `restart` после прохода по интерфейсам добавлено явное `unlink(AWG_PID_FILE)`. Ранее PID удалялся только внутри `awg_down()`, inline-блок `restart` эту функцию не вызывал.

**[BUG-6] Поля h1–h4 без ограничений в модели**
Добавлены `MinimumValue` и `MaximumValue=4294967295` (uint32 max) для полей `h1`–`h4` в `Instance.xml`. Минимум позже исправлен на 5 в v2.3.2.

**[BUG-7] Поле `interface_number` отсутствовало в форме GUI**
Поле присутствовало в модели и в service-control скрипте, но не было в `forms/instance.xml`. Добавлено между `enabled` и `name`.

**[BUG-FIX] Ошибка валидации приватного ключа при сохранении**
После введения sentinel-механизма (SEC-1) маска Base64 в `Instance.xml` применялась к строке `::file::` и вызывала ошибку "Private key must be a valid Base64 WireGuard key". Маска перенесена из XML в `InstanceController::setAction()` где выполняется до подстановки sentinel.

### Улучшения

**[IMP-4] Статус сервиса обновляется после Apply**
Добавлен вызов `updateServiceControlUI('amneziawg')` в `onAction` callback кнопки Apply. Виджет статуса в хедере OPNsense теперь обновляется сразу после успешного применения конфигурации.

**[IMP-5] Публичный ключ отображается в GUI**
Добавлен блок `.awg-pubkey-row` с `<code id="awg-pubkey-display">` и кнопкой копирования в буфер обмена. Публичный ключ показывается при загрузке страницы (из вывода `awg show` если туннель активен) и сразу после генерации новой пары.

**[IMP-6] Регистрация ACL для API endpoints**
Добавлен файл `ACL/ACL.xml` с пятью ACL-записями: `general/*`, `instance/*`, `service/*`, `import/*`, `ui/*`. Теперь доступ к API и UI плагина можно гранулярно управлять через `System → Access → Groups`.

**[IMP-7] Apply не запускает туннель когда сервис отключён**
В `ServiceController::reconfigureAction()` добавлена предварительная проверка `general.enabled`. Если сервис отключён — возвращается `status: disabled` без вызова configd.

**[IMP-8] Проверка наличия бинарей перед запуском туннеля**
Добавлена функция `awg_check_binaries()`. Перед `start`/`reconfigure` проверяется что `awg` и `awg-quick` существуют и исполняемы. При отсутствии — понятное сообщение об ошибке в лог и stdout.

**[IMP-9] Абсолютный путь для `require_once`**
Заменено `require_once('config.inc')` на `require_once('/usr/local/etc/inc/config.inc')`. Устраняет зависимость от `include_path` PHP.

**[IMP-10] Защита от параллельных reconfigure через flock()**
Добавлен эксклюзивный lock `/var/run/amneziawg.lock` для действий `start`, `stop`, `restart`, `reconfigure`. Параллельный вызов молча возвращает `OK` и выходит — не ломает configd.

**[IMP-11] Понятное сообщение при Apply с отключённым сервисом**
Ранее при `general.enabled = 0` кнопка Apply показывала красную ошибку "Error reconfiguring service. disabled". Теперь отображается синий информационный диалог: _"AmneziaWG is disabled. Enable it on the General tab and apply again."_

**[IMP-1/2/3] Валидация полей в модели**
- `address` — маска CIDR: IPv4 и IPv6, список через запятую для dual-stack
- `peer_endpoint` — маска `host:port` с поддержкой IPv4, IPv6 в `[]`, hostname
- `peer_public_key`, `peer_preshared_key` — маска Base64/44 символа WireGuard
- `private_key` — валидация перенесена в контроллер (совместима с sentinel SEC-1)

---

## v4.0.0 — 2026-02-25 (легаси-нумерация)

> ⚠️ Записи ниже относятся к ранней нумерации версий (февраль 2026: 1.0.0 → 2.0.0 → 3.0.0 → 4.0.0), после которой проект перешёл на ветку 2.1.x. Не путать с актуальным релизом v3.0.0 (2026-06-05, multi-instance) выше.

### Исправления багов

**DNS и MTU не записывались в конфиг туннеля**
Поля `DNS` и `MTU` читались из config.xml и передавались в скрипт, но функция `awg_write_conf()` не включала их в генерируемый `.conf` файл. Теперь оба поля корректно записываются в секцию `[Interface]`.

**Сервис всегда отображался как "stopped" в дашборде**
OPNsense определяет статус сервиса по наличию PID файла. `awg-quick` не создаёт PID файл самостоятельно. Добавлено создание `/var/run/amneziawg.pid` после успешного `awg-quick up` и удаление при `stop` и `down`.

**Вывод `awg-quick` засорял stdout скрипта**
В функциях `awg_up()` и `awg_down()` использовался `exec(...' 2>&1')` без захвата вывода в массив. Весь вывод `awg-quick` попадал в stdout скрипта, и `ServiceController` получал не чистый `OK`. Вывод теперь захватывается в `$out[]` и пишется только в лог.

**Кнопка Apply зависала при первом нажатии после перезагрузки страницы**
`saveFormToEndpoint` при ошибке не вызывал error-callback, промис `$.Deferred()` никогда не резолвился. Добавлен `errorCallback` с `dfObj.resolve()` в оба вызова `saveFormToEndpoint`.

**`awg show` не вызывался при запросе статуса**
Команда `status` возвращала только список интерфейсов через `ifconfig -l` без реального состояния туннеля. Теперь для каждого `awgN` вызывается `awg show <iface>`.

**Таймаут configd слишком мал для операций с туннелем**
Добавлен `timeout:60` в actions `start`, `stop`, `restart`, `reconfigure`.

### Новые возможности

**Журнал операций**
Добавлена функция `awg_log()`. Все операции записываются в `/var/log/amneziawg.log` с временными метками.

### Изменения API

`ServiceController::reconfigureAction()` возвращает как `result`, так и `status` для совместимости с разными версиями OPNsense.

---

## v3.0.0 — 2026-02-20 (легаси-нумерация)

### Первая рабочая версия

- GUI открывается, поля сохраняются
- Импорт `.conf` файла через модальное окно (Parse & Fill)
- Генерация keypair через `awg genkey | awg pubkey`
- Apply запускает туннель через `awg-quick up`
- Селективная маршрутизация через Firewall Rules + Gateway
- `Table = off` в конфиге — OPNsense управляет маршрутами
- Плоская модель Instance (не ArrayField, не bootgrid)

---

## v2.0.0 — 2026-02-18

### Переработка архитектуры

- Переход от bootgrid к плоской модели
- Устранение проблем с сохранением данных в config.xml
- Исправление mount path моделей

---

## v1.0.0 — 2026-02-15

### Первоначальная версия

- Базовая структура плагина
- Модели General и Instance
- Контроллеры API
- Интеграция с configd
