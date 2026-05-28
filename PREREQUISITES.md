# Предусловия для установки os-amneziawg

Список проверок и подготовительных шагов **перед** установкой плагина (см. [SETUP.md](SETUP.md)). Не все пункты применимы к каждой инсталляции — раздел «Платформа» зависит от того, OPNsense у тебя на физическом железе или в виртуалке.

---

## 1. Версия OPNsense и FreeBSD

Минимально поддерживаемые версии:
- **OPNsense** 25.x или 26.x
- **FreeBSD** ядро 14.x amd64

Проверить:
```sh
opnsense-version
uname -r
uname -m
```

Если версия FreeBSD старше 14.x — `amnezia-kmod` из FreeBSD-quarterly может оказаться ABI-несовместимым с твоим ядром. Установщик плагина (`install.sh`) сделает dry-run и предупредит, но безопаснее заранее обновить OPNsense до актуальной ветки.

---

## 2. Сетевые предусловия

### 2.1 WAN не должен сидеть за двойным NAT

AmneziaWG handshake — это асимметричная UDP-сессия с короткими пакетами. Двойной NAT (например, провайдер за CGN + локальный NAT гипервизора/роутера) часто ломает stateful UDP — handshake-пакеты уходят, ответы теряются.

Проверка после установки и поднятия туннеля — если в `awg show awg0` видим `0 B received, X KiB sent` и нет `latest handshake`, скорее всего проблема в этом.

**Лечение:**
- На виртуалке (Hyper-V) — переключить WAN на External Switch (см. §3 ниже).
- На физическом OPNsense за CGN провайдера — попросить у провайдера публичный IP или использовать другого провайдера.

### 2.2 Провайдерский WAN — IPv4 публичный или хотя бы статичный CGN

Если провайдер выдаёт через CGN, и UDP-mapping таймауты короткие (<60 секунд) — handshake может не успевать в окно. `PersistentKeepalive = 25` в `.conf` помогает держать NAT-mapping живым **после** первого успешного handshake, но не помогает добиться самого первого.

### 2.3 Доступ к серверу VPN

UDP к `<endpoint-IP>:<endpoint-port>` (из `.conf`) должен быть разрешён исходящим — большинство провайдеров его не режут, но корпоративные firewall'ы или ISP с DPI могут.

Проверка с OPNsense до установки плагина:
```sh
nc -uvz <endpoint-IP> <endpoint-port>
```

---

## 3. Платформа

### 3.1 Hyper-V (Windows-хост)

⚠️ **Критично:** WAN-адаптер VM **не должен** быть подключён к **Default Switch** (NAT-режим Hyper-V) — этот свитч ломает stateful UDP для WireGuard/AmneziaWG handshake. Симптом: handshake не проходит, `0 B received` в `awg show`.

Корректная настройка — **External Switch** (Bridge через физический адаптер хоста).

#### Создать External Switch

1. На хост-машине Windows запустить **Hyper-V Manager**.
2. Правая панель → **Virtual Switch Manager...**
3. **New virtual network switch** → выбрать **External** → **Create Virtual Switch**.
4. Настройки:
   - **Name:** `External - Eth` (любое имя)
   - **External network:** твой физический сетевой адаптер
   - ✅ **Allow management operating system to share this network adapter**
5. **OK** → **Apply**.

⚠️ При создании External Switch у хоста на пару секунд пропадает интернет — это нормально.

#### Переключить WAN VM на External Switch

1. **Shut Down** OPNsense VM. Делать это правильно через GUI (`System → Reboot → Halt`) либо в SSH `halt -p`. Не через Hyper-V «Power Off».
2. Settings VM → найти **WAN-адаптер** (обычно их два: WAN и LAN).
   - Если непонятно, какой WAN — обрати внимание на MAC. Можно сверить с тем, что OPNsense покажет для интерфейса с дефолтным маршрутом.
3. **Virtual switch** заменить с `Default Switch` на `External - Eth`.
4. **Apply → OK** → запустить VM.

После загрузки WAN получит IP **от твоего домашнего/офисного роутера** (например `192.168.X.Y`), и stateful UDP будет работать корректно. Если ранее WAN был на DHCP — он автоматически перезапросит DHCP и подцепится в новую подсеть.

### 3.2 VMware (ESXi / Workstation)

В сетевой настройке OPNsense VM выбирать **Bridged Adapter** (не NAT). В Workstation: VM Settings → Network Adapter → Bridged → выбрать конкретный физический интерфейс хоста (не «Auto»). В ESXi — VM использует port group на vSwitch, подключённом к физическому uplink'у.

### 3.3 VirtualBox

VM Settings → Network → Adapter 1 (WAN) → Attached to: **Bridged Adapter** → Name: физический сетевой адаптер хоста.

### 3.4 KVM / Proxmox

В Proxmox VM Settings → Network Device → Bridge: `vmbr0` (или другой Linux bridge, подключённый к физическому uplink'у). NAT-режим (`vmbr0` с masquerade) — не подходит, так же как Hyper-V Default Switch.

### 3.5 Физическое железо

Дополнительной настройки на стороне OPNsense не требуется. Но провайдер не должен сидеть за CGN — см. §2.

---

## 4. SSH-доступ к OPNsense

Большинство шагов из `SETUP.md` выполняются через веб-интерфейс, но некоторые проверки и диагностика — только через SSH.

### Включить SSH

В веб-интерфейсе OPNsense: **System → Settings → Administration → Secure Shell**:
- ✅ **Enable Secure Shell**
- ✅ **Permit root user login**
- ✅ **Permit password login** (или оставить только ключ — на твой выбор)
- **Save**.

### Подключиться

```bash
ssh root@<opnsense-IP>
```

### ⚠️ csh vs sh

Root на OPNsense (FreeBSD) по умолчанию использует **csh**, а не bash/sh. Большинство команд в инструкциях написаны под sh-синтаксис (например `cmd 2>/dev/null`). Чтобы не натыкаться на `Ambiguous output redirect` и подобные ошибки, **сразу после входа** переключаться в sh:

```sh
sh
```

После этого приглашение поменяется (с `# ` на `$ ` или похожее), и команды из инструкций будут работать без правок.

---

## 5. AmneziaWG `.conf` от сервера

Тебе нужен **актуальный** `.conf`-файл с сервера AmneziaWG. Откуда брать:

- **Self-hosted Amnezia** — через админку, либо `wg-server` утилиту на стороне сервера.
- **Amnezia Desktop client** — там есть кнопка экспорта конфига для нового устройства.
- **Коммерческий AmneziaVPN-провайдер** — личный кабинет / Telegram-бот.

Содержимое `.conf` примерно такое:

```ini
[Interface]
Address = 10.8.1.14/32
DNS = 1.1.1.1, 1.0.0.1
PrivateKey = ...
Jc = 5
Jmin = 10
Jmax = 50
S1 = 34
S2 = 134
S3 = 12        # AWG 2.0
S4 = 18        # AWG 2.0
H1 = 787134324-1593815189
H2 = 2078744051-2108541964
H3 = 2116112629-2134649029
H4 = 2146265133-2146577658
I1 = <b 0x...>  # CPS-маскировка (AWG 2.0), опционально
I2 =
I3 =
I4 =
I5 =

[Peer]
PublicKey = ...
PresharedKey = ...
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = 1.2.3.4:51820
PersistentKeepalive = 25
```

⚠️ Параметры обфускации (Jc, Jmin, Jmax, S1-S4, H1-H4) **должны точно совпадать** с серверной конфигурацией. Если конфиг сгенерирован давно (несколько месяцев назад) — попроси новый: сервер мог поменять ключи/endpoint.

⚠️ AWG 2.0 параметры (S3, S4, диапазоны H1-H4 вида `12345-67890`, I1-I5) поддерживаются начиная с `amnezia-kmod 2.0.x` и плагина версии 2.7.0+. Если у тебя `amnezia-kmod 1.x` — в `.conf` этих параметров быть не должно.

---

## 6. Резервная копия конфигурации OPNsense

Перед началом установки сделать backup:
- **System → Configuration → Backups → Download configuration**.

Если что-то пойдёт не так — можно восстановить через **Restore configuration**.

⚠️ Файл `config.xml` содержит **все** секреты OPNsense (пароли админа, приватные ключи, PSK). Хранить надёжно.

---

Дальше — [SETUP.md](SETUP.md).
