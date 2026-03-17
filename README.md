# os-amneziawg

**AmneziaWG VPN plugin for OPNsense** — v2.6.0

AmneziaWG — обфусцированный форк WireGuard для обхода DPI-блокировок. Этот плагин добавляет AmneziaWG в OPNsense как нативный VPN-клиент с поддержкой селективной маршрутизации.

---

## Возможности

- Импорт клиентского `.conf` файла одной кнопкой — **Parse & Fill**
- Полная поддержка параметров обфускации AmneziaWG (Jc, Jmin, Jmax, S1, S2, H1–H4)
- Генерация keypair прямо в GUI — публичный ключ отображается для передачи администратору сервера
- Приватный ключ хранится в защищённом файле `private.key` (0600) — не попадает в бэкапы конфига
- Управление туннелем через GUI: **VPN → AmneziaWG** — кнопки Start/Stop/Restart
- Автозапуск туннеля при перезагрузке OPNsense (rc.syshook)
- Статус туннеля через `awg show` в реальном времени
- Валидация всех полей: CIDR, host:port, Base64 ключи
- Совместимость с селективной маршрутизацией OPNsense (Firewall Rules + Gateway)
- Корректное отображение статуса сервиса в дашборде OPNsense
- Журнал операций `/var/log/amneziawg.log` с автоматической ротацией
- ACL-контроль доступа к API через `System → Access → Groups`

---

## Системные требования

| Компонент | Версия |
|---|---|
| OPNsense | 25.x / 26.x |
| FreeBSD | 14.x amd64 |
| AmneziaWG server | Любая актуальная версия |

> Пакеты `amnezia-kmod` и `amnezia-tools` отсутствуют в репозитории OPNsense. Установщик автоматически подключает FreeBSD quarterly repo и ставит их через `pkg install` (с подтверждением). Архитектуры ARM и i386 могут потребовать ручной установки.

---

## Быстрый старт

> ⚠️ Только в ознакомительных целях.

### 1. Сервер на VPS (Ubuntu/Debian)

```bash
curl -fsSL https://raw.githubusercontent.com/Toujifushiguro/vps-scripts/main/amneziawg-install.sh \
    -o amneziawg-install.sh
bash amneziawg-install.sh
```

Клиентский конфиг будет в `/etc/amnezia/amneziawg/clients/<n>.conf`.

### 2. Установка плагина на OPNsense

```bash
scp os-amneziawg/ root@<opnsense-ip>:/tmp/os-amneziawg
ssh root@<opnsense-ip>
cd /tmp/os-amneziawg
sh install.sh
```

Скрипт автоматически:
- проверит целостность `pkg` — если был обновлён из FreeBSD quarterly, предложит восстановить
- покажет текущую и новую версию плагина и запросит подтверждение
- проверит наличие `awg` и модуля ядра `if_amn`
- предложит установить недостающие пакеты из FreeBSD quarterly repo (`[Y/n]`)
- заблокирует `pkg` от самообновления на время установки из quarterly
- проверит совместимость модуля ядра с версией FreeBSD (ABI check)
- создаст временный repo-конфиг, установит пакеты, удалит конфиг
- заблокирует `amnezia-kmod` от случайного обновления (`pkg lock`)
- загрузит модуль ядра и пропишет его в `/boot/loader.conf`
- скопирует файлы плагина
- установит конфиг ротации лога newsyslog
- перезапустит configd и очистит кэш

Проверить установленную версию:
```bash
configctl amneziawg version
```

### 3. Настройка в GUI

1. Обнови браузер (Ctrl+F5) → **VPN → AmneziaWG**
2. Нажми **Import .conf** → вставь конфиг → **Parse & Fill**
3. Поле **DNS** — оставь пустым, если используешь Unbound (рекомендуется)
4. Перейди на вкладку **General** → поставь галочку **Enable AmneziaWG**
5. Нажми **Apply**
6. Проверь туннель:

```bash
awg show
# Должен показать: latest handshake: N seconds ago
```

### 4. Интерфейс и шлюз

| Шаг | Путь в GUI |
|---|---|
| Назначить интерфейс | Interfaces → Assignments → awg0 → **+** |
| Настроить интерфейс | Interfaces → AWG_VPN → Enable, IPv4: None |
| Создать шлюз | System → Gateways → **+** |

Параметры шлюза:
- **Interface:** AWG_VPN
- **Gateway IP:** IP пира из туннеля (например `10.8.1.1`)
- **Far Gateway:** ✅
- **Disable Monitoring:** ✅

### 5. MSS Clamping (обязательно!)

Без этого видео и тяжёлые страницы могут не работать.

**Firewall → Settings → Normalization → +**

| Поле | Значение |
|---|---|
| Interface | AWG_VPN |
| Protocol | TCP |
| Max MSS | 1380 |

### 6. Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило:
  - Source: `LAN net`
  - Destination: созданный alias
  - Gateway: `AWG_GW`

### 7. Outbound NAT (обязательно!)

Без этого трафик через туннель не будет NATиться и не уйдёт дальше VPN-сервера.

**Firewall → NAT → Outbound**

1. Переключи режим на **Hybrid outbound NAT rule generation** (если ещё не переключён)
2. Добавь правило **+**:

| Поле | Значение |
|---|---|
| Interface | AWG (awg) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

---

## Устранение неполадок

### Диагностика — с чего начать

```bash
# 1. Версия плагина
configctl amneziawg version

# 2. Статус туннеля
awg show

# 3. Лог операций плагина
cat /var/log/amneziawg.log

# 4. Модуль ядра загружен?
kldstat | grep amn

# 5. Бинарники на месте?
ls -la /usr/local/bin/awg /usr/local/bin/awg-quick

# 6. Сгенерированный конфиг
cat /usr/local/etc/amnezia/awg0.conf

# 7. Интерфейс существует?
ifconfig -a | grep awg
```

### `pkg update` падает с Segmentation fault

Если после установки плагина `pkg update` завершается с `Segmentation fault` — это значит, что `pkg` был обновлён из FreeBSD quarterly repo до несовместимой с OPNsense версии.

**Автоматическое исправление:** переустановите плагин — `sh install.sh` обнаружит проблему и предложит восстановить `pkg`.

**Ручное исправление:**
```bash
pkg-static install -f pkg       # восстановить pkg из репозитория OPNsense
pkg-static update -f             # обновить каталоги
pkg lock amnezia-kmod            # заблокировать kmod от случайного обновления
```

### Kernel panic / случайные ребуты после установки

Модуль ядра `amnezia-kmod` из FreeBSD quarterly может быть собран для другой версии FreeBSD, чем использует OPNsense. Это вызывает kernel panic.

```bash
# Проверить версию ядра
uname -r

# Проверить из какого репозитория установлен kmod
pkg query '%R' amnezia-kmod

# Если версии не совпадают — удалить и переустановить
pkg unlock amnezia-kmod
pkg delete amnezia-kmod
# Установить совместимую версию или дождаться обновления пакета
```

### Зависание при загрузке на "Configuring AmneziaWG"

Если модуль ядра `if_amn` не загружается (несовместимость с ядром), boot hook может зависнуть. В v2.6.0+ добавлена проверка: если `kldload if_amn` не удаётся — запуск туннеля пропускается без зависания.

```bash
# Проверить лог загрузки
cat /tmp/amneziawg_syshook.log

# Если видите "FATAL: cannot load if_amn" — модуль несовместим с ядром
# Переустановите amnezia-kmod или дождитесь обновления
```

### Туннель не поднимается

```bash
# Запустить вручную и посмотреть вывод
php /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php reconfigure
```

| Ошибка в логе | Причина | Решение |
|---|---|---|
| `ERROR: binary not found` | awg/awg-quick не установлены | Запусти `install.sh` заново |
| `ERROR: if_amn kernel module not available` | Модуль ядра не загружается | Переустанови amnezia-kmod, проверь `kldload if_amn` |
| `ERROR: private key file not found` | Файл ключа отсутствует | Сгенерируй keypair в GUI |
| `up awg0 rc=1` | Ошибка конфига или модуль не загружен | Проверь `kldstat`, проверь конфиг |
| `SKIP: another instance` | Lock занят параллельным процессом | Подожди или удали `/var/run/amneziawg.lock` |
| `EXEC TIMEOUT: 30s` | awg-quick завис | Процесс убит автоматически, проверь сеть/DNS |

Если модуль ядра не загружен:
```bash
kldload if_amn
grep -q 'if_amn_load' /boot/loader.conf || echo 'if_amn_load="YES"' >> /boot/loader.conf
```

### Сервис "stopped" в дашборде после перезагрузки

AmneziaWG запускается автоматически при загрузке через syshook (`/usr/local/etc/rc.syshook.d/start/50-amneziawg`), если `General → Enable AmneziaWG` включён. Если не стартует:

```bash
# Проверь лог
tail /var/log/amneziawg.log

# Проверь syshook скрипт
cat /usr/local/etc/rc.syshook.d/start/50-amneziawg

# Запусти вручную
configctl amneziawg start
```

### Меню VPN → AmneziaWG не появляется

```bash
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5 в браузере
```

### Частичная работа (сайты открываются, видео/тяжёлые страницы не грузят)

Не настроен MSS Clamping (шаг 5). **Firewall → Settings → Normalization → +**:
- Interface: AWG_VPN, Protocol: TCP, Max MSS: **1380**
- Если не помогает — попробуй 1360 или 1280

### Кнопка Apply / Start / Stop не работает ("No response from configd")

```bash
# 1. Перезапусти configd
service configd restart

# 2. Проверь lock-файл — зависший процесс?
cat /var/run/amneziawg.lock
# Если показывает PID:
kill -9 $(cat /var/run/amneziawg.lock) 2>/dev/null
rm -f /var/run/amneziawg.lock
service configd restart

# 3. Посмотри лог
tail -20 /var/log/amneziawg.log
```

> Lock-файл имеет автоматическое восстановление: если процесс-владелец мёртв — lock переберётся автоматически. Если завис дольше 120 секунд — будет убит принудительно.

### Apply показывает "AmneziaWG is disabled"

Это ожидаемое поведение когда `General → Enable AmneziaWG` не отмечен. Включи сервис и нажми Apply снова.

### Проблемы с приватным ключом

```bash
# Файл ключа существует?
ls -la /usr/local/etc/amnezia/private.key

# В config.xml должен быть sentinel (не сам ключ!)
grep -A1 'private_key' /conf/config.xml
# Ожидается: ::file::

# Перегенерировать пару ключей
configctl amneziawg gen_keypair
```

### Где искать логи

| Лог | Путь | Содержит |
|---|---|---|
| Плагин | `/var/log/amneziawg.log` | Все операции с временными метками |
| PHP ошибки | `/var/lib/php/tmp/PHP_errors.log` | Ошибки OPNsense PHP |
| Система | `/var/log/system/latest.log` | Ошибки configd |

### Полезные команды

```bash
awg show                               # статус туннеля, handshake
awg show awg0 transfer                 # переданные байты
configctl amneziawg status             # статус через configd (JSON)
configctl amneziawg version            # версия плагина
ifconfig awg0                          # детали интерфейса
netstat -rn | grep awg                 # таблица маршрутизации
tail -f /var/log/amneziawg.log         # мониторинг лога в реальном времени
```

### Удаление плагина

```bash
sh install.sh uninstall
```

> При удалении: предлагается удалить пакеты `amnezia-kmod` и `amnezia-tools`, очищается запись `if_amn_load` из `/boot/loader.conf`. Директория `/usr/local/etc/amnezia/` (включая `private.key` и `.conf` файлы) удаляется автоматически.

---

## Структура файлов

```
plugin/
├── scripts/AmneziaWG/
│   └── amneziawg-service-control.php     # Управление туннелем: start/stop/reconfigure/status/gen_keypair
├── service/conf/actions.d/
│   └── actions_amneziawg.conf            # Команды configd
├── etc/
│   ├── inc/plugins.inc.d/
│   │   └── amneziawg.inc                 # Регистрация сервиса в OPNsense
│   └── newsyslog.conf.d/
│       └── amneziawg.conf                # Ротация лога (1MB / daily, 5 архивов, gzip)
└── mvc/app/
    ├── models/OPNsense/AmneziaWG/
    │   ├── General.xml / General.php      # Модель: флаг enabled
    │   ├── Instance.xml / Instance.php    # Модель: все параметры туннеля (плоская)
    │   ├── ACL/ACL.xml                    # ACL: права доступа к API и UI
    │   └── Menu/Menu.xml                  # Пункт меню VPN → AmneziaWG
    ├── controllers/OPNsense/AmneziaWG/
    │   ├── IndexController.php            # Рендеринг страницы
    │   ├── Api/GeneralController.php      # API: get/set general.enabled
    │   ├── Api/InstanceController.php     # API: get/set + genKeyPair (SEC-1/SEC-2)
    │   ├── Api/ServiceController.php      # API: reconfigure/start/stop/restart/tunnelStatus/version
    │   ├── Api/ImportController.php       # API: парсинг .conf файла (POST only)
    │   └── forms/
    │       ├── general.xml                # Форма общих настроек
    │       └── instance.xml              # Форма параметров туннеля
    └── views/OPNsense/AmneziaWG/
        └── general.volt                   # Шаблон GUI (вкладки Instance + General)
```

**Файлы на OPNsense после установки:**
```
/usr/local/etc/amnezia/private.key          (0600) — приватный ключ (не в бэкапах)
/usr/local/etc/amnezia/awg0.conf            (0600) — конфиг туннеля
/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt — версия плагина
/var/run/amneziawg.pid                             — PID файл статуса
/var/run/amneziawg.lock                            — lock файл от параллельных запусков
/var/log/amneziawg.log                             — лог операций
```

---

## Архитектурные решения

**Table = off** — `awg-quick` не трогает таблицу маршрутизации. Маршрутами управляет OPNsense через Firewall Rules + Gateway. Это позволяет реализовать селективную маршрутизацию идентично Xray/WireGuard плагинам.

**Плоская модель** — один туннель, одна модель без ArrayField. XML-путь: `//OPNsense/amneziawg/instance`. Подходит для большинства сценариев использования AmneziaWG как клиента.

**Sentinel private.key** — в `config.xml` хранится строка `::file::` вместо ключа. Реальный ключ в `private.key` (0600). `InstanceController` перехватывает `get`/`set`/`genKeyPair` и управляет файлом напрямую. GUI показывает bullet-плейсхолдер.

**Конфиг туннеля** записывается в `/usr/local/etc/amnezia/awg0.conf` (права 0600) при каждом Apply.

**PID файл** `/var/run/amneziawg.pid` создаётся после успешного `awg-quick up` и удаляется при `stop` — обеспечивает корректный статус в дашборде OPNsense.

**flock защита** — параллельные вызовы `reconfigure` (от configd и ручного запуска) не конкурируют: второй вызов немедленно возвращает `OK` и выходит.

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Автор

**Меркулов Павел Сергеевич**
Февраль–Март 2026

## Благодарности

- [AmneziaVPN](https://github.com/amnezia-vpn) — за разработку AmneziaWG
- [OPNsense](https://opnsense.org) — за открытую архитектуру плагинов
- [Toujifushiguro](https://github.com/Toujifushiguro) — за скрипт установки сервера
