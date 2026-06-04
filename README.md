# os-amneziawg

**AmneziaWG VPN plugin for OPNsense** — v3.0.0

AmneziaWG — обфусцированный форк WireGuard для обхода DPI-блокировок. Этот плагин добавляет AmneziaWG в OPNsense как нативный VPN-клиент с поддержкой **нескольких туннелей одновременно** и селективной маршрутизации.

> ⚠️ **Перед началом** прочитай [PREREQUISITES.md](PREREQUISITES.md) — там перечислены сетевые предусловия (особенно если OPNsense в виртуалке: Hyper-V/VMware/VirtualBox/KVM), про csh→sh на FreeBSD и про получение `.conf` от сервера.

---

## Возможности

- **Multi-instance** (с v3.0.0): до 100 туннелей `awg0`–`awg99` одновременно — например, два независимых VPN-провайдера или разные туннели для разных Firewall Rules / Gateway Groups
  - Грид туннелей с диалогом редактирования; toggle enabled прямо в строке
  - **Per-row Start/Stop** — управление каждым туннелем отдельно, не трогая остальные
  - Watchdog перезапускает только упавший туннель — живые сессии не рвутся
  - Автомиграция конфигурации с одно-туннельных версий 2.x при обновлении
- Импорт клиентского `.conf` файла одной кнопкой — **Parse & Fill** открывает диалог нового туннеля с заполненными полями
- **Полная поддержка AmneziaWG 2.0** (с v2.7.0):
  - Базовые параметры обфускации: Jc, Jmin, Jmax, S1, S2
  - **S3, S4** — handshake cookie / transport message padding
  - **H1–H4** — magic headers с поддержкой **диапазонов** (`12345-67890`)
  - **I1–I5** — CPS (Custom Protocol Signature) пакеты для DPI-маскировки
- Генерация keypair прямо в диалоге туннеля — публичный ключ отображается для передачи администратору сервера
- Приватные ключи в защищённых файлах `<uuid>.key` (0600, один на туннель) — не попадают в бэкапы конфига
- Управление через GUI: **VPN → AmneziaWG** — сервисные кнопки Start/Stop/Restart + per-row кнопки в гриде
- Автозапуск всех включённых туннелей при перезагрузке OPNsense (rc.syshook)
- Per-instance диагностика: селектор туннеля на вкладке Diagnostics, статус через `awg show` в реальном времени
- **Test Connection с подсказками**: при ошибке плагин сам определяет причину (нет handshake / нет маршрута / DNS / сервер не отвечает) и подсказывает следующий шаг
- Валидация всех полей: CIDR, host:port, Base64 ключи, диапазоны H1-H4, уникальность номеров интерфейсов
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
| AmneziaWG server | Любая актуальная версия (AWG 1.x или 2.x) |
| `amnezia-kmod` | 2.0.x (для поддержки S3/S4, H-диапазонов, I1-I5) |
| `amnezia-tools` | 1.0.20250903+ |

> Пакеты `amnezia-kmod` и `amnezia-tools` отсутствуют в репозитории OPNsense. Установщик автоматически подключает FreeBSD quarterly repo и ставит их через `pkg install` (с подтверждением). Архитектуры ARM и i386 могут потребовать ручной установки.

> Если используется `amnezia-kmod` 1.x — параметры AWG 2.0 (S3, S4, диапазоны H1-H4, I1-I5) в `.conf` должны отсутствовать.

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
sh                          # переключиться из csh в sh (см. PREREQUISITES.md)
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

### 3. Импорт `.conf` в GUI

1. Обнови браузер (Ctrl+F5) → **VPN → AmneziaWG** → вкладка **Tunnels**
2. Нажми **Import .conf** → вставь конфиг → **Parse & Fill** — откроется диалог нового туннеля с заполненными полями
3. Проверь, что все поля заполнились корректно. Для AWG 2.0 особое внимание на S3, S4, диапазоны H1-H4 (формат `12345-67890`), I1
4. Поле **DNS** — оставь пустым, если используешь Unbound (рекомендуется)
5. Заполни **Name** (имя туннеля) → **Save**. Номер интерфейса (`awg<N>`) подставляется автоматически
6. На вкладке **General** проверь галочку **Enable AmneziaWG** → нажми **Apply**
7. Для второго и последующих туннелей — повтори импорт: каждый получит свой `awg<N>`
8. Проверь туннель:

```bash
sh
awg show awg0
# Должен показать: latest handshake: N seconds ago
# transfer: <ненулевое> received, <ненулевое> sent
```

⚠️ AWG 2.0 параметры (S3, S4, H-диапазоны, I1) **должны точно совпадать** с серверной конфигурацией. Любое расхождение в S1-S4 / H1-H4 → handshake не пройдёт. Если `received: 0 B` — см. раздел [Устранение неполадок](#устранение-неполадок).

### 4. Создание интерфейса и шлюза

**Interfaces → Assignments**:
1. Внизу страницы в выпадающем списке выбрать `awg0` → нажать **+**.
2. Кликнуть на появившийся `opt1`.
3. Заполнить:
   - ✅ **Enable Interface**
   - **Description:** `AWG`
   - **IPv4 Configuration Type:** `None`
   - **MTU** и **MSS:** оставить **пустыми** (см. [Почему MTU/MSS пустые](#почему-mtumss-пустые))
4. **Save → Apply changes**.

**System → Gateways → Configuration → +Add**:

| Поле | Значение |
|------|----------|
| **Name** | `AWG_GW` |
| **Interface** | `AWG` |
| **IP address** | `10.8.1.1` — адрес сервера **внутри туннеля** (обычно `.1` подсети клиента) |
| **Far Gateway** | ✅ |
| **Disable Gateway Monitoring** | ✅ |

**Save → Apply**. На странице **System → Gateways → Status** статус AWG_GW должен стать `Online` (или `Unknown` при включённом Disable Monitoring).

> **Как узнать IP сервера в туннеле**, если не указан в `.conf`: спросить админа VPN, либо угадать (`.1` от подсети клиента — если клиент `10.8.1.14/32`, то сервер `10.8.1.1`), либо эмпирически: `ping -c 3 -S 10.8.1.14 10.8.1.1` из SSH OPNsense.

#### Почему MTU/MSS пустые

`awg-quick` (часть `amnezia-tools`) сам определяет корректный MTU для туннеля с учётом overhead AmneziaWG обфускации — обычно получается **1408** для WAN с MTU 1500. Pf в свою очередь автоматически делает MSS clamping в TCP-SYN на основе фактического MTU интерфейса. Поэтому ручная настройка в большинстве случаев не нужна.

Если возникают проблемы — TLS handshake виснет, видео не открывается, веб-страницы загружаются «наполовину» — см. [HTTPS-handshake виснет / медленный YouTube](#https-handshake-виснет--медленный-youtube).

### 5. Aliases для селективной маршрутизации

Два alias-а: один для конкретных доменов с фиксированными IP, второй для крупных CDN (Google, Cloudflare и т.п.) с CIDR-блоками.

#### Alias `vpn_domains` (Host(s))

**Firewall → Aliases → +Add**:

| Поле | Значение |
|------|----------|
| **Enabled** | ✅ |
| **Name** | `vpn_domains` |
| **Type** | `Host(s)` |
| **Content** | список доменов по одной строке (`ifconfig.me`, `cp.cloudflare.com`, `example.com` и т.п.) |

**Save → Apply**. Подходит для доменов со **стабильными** IP — личные API, небольшие сайты.

#### Alias `google_nets` (Network(s)) — для Google CDN

> ⚠️ Для Google/YouTube/Cloudflare и других крупных CDN domain-based alias **не работает надёжно** — IP ротируются быстрее, чем OPNsense успевает резолвить (типовая периодичность 5 минут). Решение: занести подсети ASN провайдера.

**Firewall → Aliases → +Add**:

| Поле | Значение |
|------|----------|
| **Enabled** | ✅ |
| **Name** | `google_nets` |
| **Type** | `Network(s)` |
| **Content** | список CIDR-подсетей AS15169 (см. ниже) |

CIDR-подсети Google (актуальный список — обновлять с https://www.gstatic.com/ipranges/goog.json, либо см. раздел [Автообновление CIDR-блоков](#автообновление-cidr-блоков-google)):

<details>
<summary>Развернуть список (≈70 подсетей)</summary>

```
8.8.4.0/24
8.8.8.0/24
8.34.208.0/20
8.35.192.0/20
23.236.48.0/20
23.251.128.0/19
34.0.0.0/15
34.2.0.0/16
34.3.0.0/23
34.4.0.0/14
34.8.0.0/13
34.16.0.0/12
34.32.0.0/11
34.64.0.0/10
34.128.0.0/10
35.184.0.0/13
35.192.0.0/14
35.196.0.0/15
35.198.0.0/16
35.199.0.0/17
35.199.128.0/18
35.200.0.0/13
35.208.0.0/12
35.224.0.0/12
35.240.0.0/13
64.15.112.0/20
64.233.160.0/19
66.22.228.0/23
66.102.0.0/20
66.249.64.0/19
70.32.128.0/19
72.14.192.0/18
74.125.0.0/16
104.154.0.0/15
104.196.0.0/14
107.167.160.0/19
107.178.192.0/18
108.59.80.0/20
108.170.192.0/18
108.177.0.0/17
130.211.0.0/16
136.22.160.0/20
136.22.176.0/21
136.22.184.0/23
136.22.186.0/24
142.250.0.0/15
146.148.0.0/17
162.216.148.0/22
162.222.176.0/21
172.110.32.0/21
172.217.0.0/16
172.253.0.0/16
173.194.0.0/16
173.255.112.0/20
192.158.28.0/22
192.178.0.0/15
193.186.4.0/24
199.36.154.0/23
199.36.156.0/24
199.192.112.0/22
199.223.232.0/21
207.223.160.0/20
208.65.152.0/22
208.68.108.0/22
208.81.188.0/22
208.117.224.0/19
209.85.128.0/17
216.58.192.0/19
216.73.80.0/20
216.239.32.0/19
```

</details>

Для других крупных CDN — добавлять подобные alias:
- Cloudflare: https://www.cloudflare.com/ips/
- ASN-диапазоны: https://bgp.he.net/AS&lt;номер&gt;

### 6. Firewall Rule — policy-based routing

**Firewall → Rules → LAN → +Add**:

| Поле | Значение |
|------|----------|
| **Action** | `Pass` |
| **Quick** | ✅ |
| **Interface** | `LAN` |
| **Direction** | `in` |
| **TCP/IP Version** | `IPv4` |
| **Protocol** | `any` |
| **Source** | `LAN net` |
| **Destination** | `vpn_domains,google_nets` (через запятую, OPNsense объединит) |
| **Description** | `Route vpn_domains via AWG` |
| **Gateway** | `AWG_GW` (в OPNsense 25.x поле спрятано в разделе **Advanced features**; в 26.x — в основном блоке формы) |

**Save → Apply changes**.

⚠️ Правило должно стоять **выше** правила «Default allow LAN to any». Если ниже — перетащить.

### 7. Outbound NAT

Без NAT пакеты уйдут из awg0 с source IP LAN-клиента, сервер VPN не сможет ответить.

**Firewall → NAT → Outbound**:

1. Переключить режим на **Hybrid outbound NAT rule generation** → **Save & Apply**.
2. В **Manual rules** → **+Add**:

| Поле | Значение |
|------|----------|
| **Interface** | `AWG` |
| **TCP/IP Version** | `IPv4` |
| **Protocol** | `any` |
| **Source** | `LAN net` |
| **Destination** | `any` |
| **Translation / target** | `Interface address` |
| **Description** | `NAT LAN -> AWG` |

**Save → Apply changes**.

#### Проверка NAT

```sh
sh
pfctl -s nat | grep awg0
```

Должно быть:
```
nat on awg0 inet from (hn1:network) to any -> (awg0:0) port 1024:65535
```

(`hn1` — имя LAN-интерфейса OPNsense; на Hyper-V — `hn0/hn1`, VMware — `vmx0/vmx1`, физика — `em0/em1`, `igb0/igb1` и т.п. Узнать своё имя: **Interfaces → Overview**, колонка **Device**.)

### 8. Проверка селективной маршрутизации

С LAN-клиента (Linux):

```bash
sudo resolvectl flush-caches

# Запрос к домену ВНЕ alias — должен показать твой провайдерский IP
curl https://api.ipify.org
# → твой WAN-IP провайдера

# Запрос к домену В alias — должен показать IP VPN-сервера
curl ifconfig.me
# → IP AmneziaWG-endpoint или близкий
```

Параллельно на OPNsense:
```sh
sh
awg show awg0
# transfer: received и sent должны расти

pfctl -s state | grep awg0
# должны быть live-соединения через туннель
```

В браузере на LAN-клиенте — открыть YouTube. Видео должно проигрываться (если `google_nets` в правиле).

---

## DNS over TLS (опционально, но рекомендуется)

Без DoT провайдер видит, какие домены резолвит твой роутер. Включение DoT шифрует DNS-запросы.

### Добавить DoT-серверы

**Services → Unbound DNS → DNS over TLS → +Add**. Создать **2 записи** (Cloudflare):

| Поле | Запись 1 | Запись 2 |
|------|----------|----------|
| Enabled | ✅ | ✅ |
| Server IP | `1.1.1.1` | `1.0.0.1` |
| Server Port | `853` | `853` |
| Verify CN | `cloudflare-dns.com` | `cloudflare-dns.com` |

**Save → Apply**.

### Включить DNSSEC

**Services → Unbound DNS → General** → ✅ **Enable DNSSEC Support** → **Save → Apply**.

### Убрать провайдерские DNS

**System → Settings → General**:
- Все поля **DNS servers** очистить
- **DNS server options** → снять галочку «Allow DNS server list to be overridden by DHCP/PPP on WAN»
- **Save**

### Проверить, что DHCP отдаёт клиентам сам OPNsense как DNS

**Services → ISC DHCPv4 → [LAN]** (или **Kea DHCP → Subnets**) → поле **DNS servers** — **пусто** → **Save → Apply**.

### Проверка DoT

С LAN-клиента:
```bash
nslookup example.com
```
В ответе **Server** должен быть IP **самого OPNsense**.

На OPNsense:
```sh
sh
tcpdump -ni <wan-iface> port 853 -c 5
```
Параллельно с клиента сделать DNS-запросы — должны полететь пакеты в `1.1.1.1.853`.

---

## Опциональные доработки

Три независимых раздела, любой можно пропустить.

### DNS Query Forwarding — резолв зон через DNS внутри туннеля

**Зачем.** Если домен заблокирован на провайдерском DNS-уровне (DNS poisoning — ISP возвращает «не тот» IP или NXDOMAIN), то даже при policy-based routing через AWG_GW трафик уйдёт не туда, потому что **резолв происходит до маршрутизации**.

**Настройка.** **Services → Unbound DNS → Query Forwarding → +Add**:

| Поле | Значение |
|------|----------|
| **Enabled** | ✅ |
| **Domain** | `example.com` (конкретный домен или TLD: `ru.`, `youtube.com`) |
| **Server IP** | `10.8.1.1` (DNS внутри туннеля), либо `1.1.1.1` |
| **Server Port** | `53` |

**Save → Apply**.

⚠️ При активном DNSSEC валидация для forwarding-зон может пропускаться (поведение Unbound) — для обхода ISP-DNS это не критично.

### Kill switch — блокировать VPN-трафик, если туннель упал

**Зачем.** Если туннель неожиданно упадёт, трафик к доменам из `vpn_domains`/`google_nets` по умолчанию **уйдёт через WAN** провайдера (DNS-leak / IP-leak).

**Принцип:** правило Pass с gateway=AWG_GW + ниже Block без gateway. Когда AWG_GW Online — Pass работает (quick). Когда AWG_GW Down — Pass пропускается, срабатывает Block.

Сначала убедиться, что **System → Settings → General → Skip rules when gateway is down** включено (обычно по умолчанию).

**Firewall → Rules → LAN → +Add**:

| Поле | Значение |
|------|----------|
| **Action** | `Block` |
| **Quick** | ✅ |
| **Interface** | `LAN` |
| **Direction** | `in` |
| **Source** | `LAN net` |
| **Destination** | `vpn_domains,google_nets` (тот же набор) |
| **Description** | `Kill switch — block VPN destinations if tunnel is down` |
| **Gateway** | (не задавать, оставить default) |

**Save → Apply**. Порядок правил:

```
1. Pass  LAN net → vpn_domains,google_nets → AWG_GW   (quick)
2. Block LAN net → vpn_domains,google_nets           (kill switch)
3. Pass  LAN net → any                                (default allow)
```

**Проверка:** `configctl amneziawg stop` → с клиента `curl --max-time 5 https://ifconfig.me` должен таймаутиться, а `curl https://api.ipify.org` — отдавать WAN-IP. Запустить туннель обратно: `configctl amneziawg start && pfctl -F state`.

### Автообновление CIDR-блоков Google

**Зачем.** Google периодически меняет/добавляет/удаляет свои CIDR-подсети (AS15169). Раз в несколько месяцев список из §5 становится неактуальным.

OPNsense имеет встроенную фичу **URL Table (IPs)** alias — сам периодически скачивает URL и обновляет таблицу pf.

**Настройка.**

1. **Firewall → Aliases → google_nets** → **Edit**.
2. **Type:** заменить `Network(s)` на **`URL Table (IPs)`**.
3. **Content:** URL plain-text feed с CIDR-блоками:
   - https://raw.githubusercontent.com/lord-alfred/ipranges/main/google/ipv4.txt — community-feed, обновляется ежедневно из официального goog.json
4. **Refresh frequency:** `1` день.
5. **Save → Apply**.

⚠️ URL Table принимает только plain-text формат (один CIDR на строку). JSON не примет.

**Проверка:**
```sh
sh
pfctl -t google_nets -T show | wc -l
```
Должно быть несколько десятков CIDR. Лог обновлений: `/var/log/aliastables/google_nets.log`.

<details>
<summary>Альтернатива — собственный скрипт-конвертер (если не доверяешь community feed)</summary>

```sh
sh
mkdir -p /usr/local/etc/amnezia/feeds

cat > /usr/local/etc/amnezia/feeds/update-google-cidr.sh << 'EOF'
#!/bin/sh
TMP=$(mktemp)
fetch -qo "$TMP" https://www.gstatic.com/ipranges/goog.json || exit 1
jq -r '.prefixes[].ipv4Prefix // empty' "$TMP" | sort -u > /var/db/aliastables/google_nets.txt
rm "$TMP"
pfctl -t google_nets -T replace -f /var/db/aliastables/google_nets.txt
EOF

chmod 755 /usr/local/etc/amnezia/feeds/update-google-cidr.sh
pkg install -y jq  # если jq не установлен

echo '0 4 * * 0  root  /usr/local/etc/amnezia/feeds/update-google-cidr.sh' >> /etc/crontab
service cron restart
```

⚠️ Этот вариант обходит OPNsense URL Table — alias `google_nets` остаётся типа `Network(s)`, скрипт напрямую обновляет pf-таблицу. После рестарта OPNsense таблица перезагружается из config.xml (статический список), поэтому скрипт нужно запускать после `configctl amneziawg restart` или включить в `/usr/local/etc/rc.syshook.d/start/`.

</details>

---

## Устранение неполадок

### Диагностика — с чего начать

```bash
sh                                       # из csh в bash (см. PREREQUISITES.md)

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

### `awg show awg0` → `0 B received, X KiB sent`, нет latest handshake

Handshake не доходит до сервера или сервер не отвечает.

1. **Проверить, что OPNsense не за двойным NAT** (см. [PREREQUISITES.md §2-3](PREREQUISITES.md)). На Hyper-V обязательно External Switch (Default Switch ломает stateful UDP для AmneziaWG handshake). На физическом OPNsense — убедиться, что WAN не за CGN провайдера.
2. **Сравнить параметры с .conf.** Все S1-S4, H1-H4, Jc/Jmin/Jmax, PublicKey, PresharedKey, Endpoint должны **точно** совпадать с тем, что выдал сервер. I1 может отличаться (CPS-маскировка не валидируется сервером).
3. **Проверить, что `.conf` не устарел.** Если конфигу больше нескольких недель — попросить у админа/провайдера новый.
4. **Tcpdump на WAN** во время `configctl amneziawg restart`:
   ```sh
   route -n get <peer-endpoint-IP>          # узнать имя WAN
   tcpdump -ni <wan-iface> host <peer-endpoint-IP> -nn
   ```
   Должно быть обоих направлений (исходящий **и** входящий).

### `pkg update` падает с Segmentation fault

Если после установки плагина `pkg update` завершается с `Segmentation fault` — `pkg` был обновлён из FreeBSD quarterly repo до несовместимой с OPNsense версии.

**Автоматическое исправление:** переустановить плагин — `sh install.sh` обнаружит проблему и предложит восстановить `pkg`.

**Ручное исправление:**
```bash
pkg-static install -f pkg       # восстановить pkg из репозитория OPNsense
pkg-static update -f             # обновить каталоги
pkg lock amnezia-kmod            # заблокировать kmod от случайного обновления
```

### Kernel panic / случайные ребуты после установки

Модуль ядра `amnezia-kmod` из FreeBSD quarterly может быть собран для другой версии FreeBSD, чем использует OPNsense.

```bash
uname -r                                # версия ядра
pkg query '%R' amnezia-kmod             # из какого репозитория
# Если несовместимо:
pkg unlock amnezia-kmod
pkg delete amnezia-kmod
# Установить совместимую версию или дождаться обновления пакета
```

### Зависание при загрузке на "Configuring AmneziaWG"

Если модуль ядра `if_amn` не загружается (несовместимость с ядром), boot hook может зависнуть. В v2.6.0+ добавлена проверка: если `kldload if_amn` не удаётся — запуск туннеля пропускается.

```bash
cat /tmp/amneziawg_syshook.log
# Если "FATAL: cannot load if_amn" — модуль несовместим с ядром
```

### Туннель работает, `ifconfig.me` через `curl` показывает WAN-IP

Домен не в alias, либо его актуальный IP ещё не зарезолвлен.

1. `host <домен>` — узнать актуальный IP.
2. `pfctl -t vpn_domains -T show | grep <IP>` — проверить, есть ли IP в таблице.
3. Если нет — нажать **Apply changes** на alias, подождать 1-5 мин.
4. Если домен динамичный (CDN, например Google) — использовать `Network(s)` alias с CIDR-блоками (§5).

### HTTPS-handshake виснет / медленный YouTube

PMTU Black Hole для TCP. Маленькие запросы (DNS, простой HTTP) проходят, большие (TLS ClientHello, видеостриминг) — теряются в туннеле.

**Симптомы:**
- `curl https://ifconfig.me/` — таймаут на `TLS handshake, Client hello`
- TCP-handshake проходит, дальше зависает
- В `curl -v` видно `Trying X.X.X.X:443... ALPN: curl offers h2,http/1.1` и пауза до timeout

**Диагностика — измерить PMTU.**

Сначала убедиться, что на интерфейсе нет искусственных ограничений:

1. **VPN → AmneziaWG → Tunnels → Edit туннеля → MTU** → пусто → **Save → Apply**.
2. **Interfaces → AWG → MTU** → пусто → **Save → Apply**.
3. **Interfaces → AWG → MSS** → пусто → **Save → Apply**.
4. `configctl amneziawg restart`.
5. `ifconfig awg0 | grep mtu` — должно быть значение, которое awg-quick рассчитал автоматически (обычно 1408 для Ethernet WAN).

С LAN-клиента ping-sweep к домену **из alias**:

```bash
for size in 1500 1450 1420 1408 1400 1380 1360 1340 1300 1280; do
  echo -n "size=$size: "
  ping -M do -s $((size - 28)) -c 1 -W 2 <домен_из_alias> > /dev/null 2>&1 && echo OK || echo FAIL
done
```

Найти максимальный `size`, при котором ещё `OK` — это и есть **реальный PMTU end-to-end** через туннель.

**Решение.** Если реальный PMTU **меньше** того, что выставил awg-quick:

| Поле | Значение |
|------|----------|
| **VPN → AmneziaWG → Tunnels → Edit туннеля → MTU** | найденный PMTU (напр. `1380`) |
| **Interfaces → AWG → MTU** | пусто (не дублировать) |
| **Interfaces → AWG → MSS** | PMTU − 40 (напр. `1340`) |

**Apply** → `configctl amneziawg restart` → `pfctl -F state`.

⚠️ `pfctl -F state` сбрасывает все pf-state-записи, включая твою SSH-сессию — она зависнет на ~30 секунд и закроется. Просто переподключись.

Если в нестандартной сети не хватает — можно понижать ещё (1280/1240 — безопасный нижний край, гарантированно проходит везде).

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

AmneziaWG запускается автоматически через syshook (`/usr/local/etc/rc.syshook.d/start/50-amneziawg`), если `General → Enable AmneziaWG` включён. Если не стартует:

```bash
tail /var/log/amneziawg.log
cat /usr/local/etc/rc.syshook.d/start/50-amneziawg
configctl amneziawg start
```

### Меню VPN → AmneziaWG не появляется

```bash
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5 в браузере
```

### Кнопка Apply / Start / Stop не работает ("No response from configd")

```bash
service configd restart

# Проверь lock-файл — зависший процесс?
cat /var/run/amneziawg.lock
# Если показывает PID:
kill -9 $(cat /var/run/amneziawg.lock) 2>/dev/null
rm -f /var/run/amneziawg.lock
service configd restart

tail -20 /var/log/amneziawg.log
```

> Lock-файл имеет автоматическое восстановление: если процесс-владелец мёртв — lock переберётся автоматически. Если завис дольше 120 секунд — будет убит принудительно.

### Apply показывает "AmneziaWG is disabled"

Это ожидаемое поведение когда `General → Enable AmneziaWG` не отмечен.

### Проблемы с приватным ключом

```bash
# Файлы ключей существуют? (один <uuid>.key на туннель)
ls -la /usr/local/etc/amnezia/*.key

# В config.xml должен быть sentinel (не сам ключ!)
grep -B4 'private_key' /conf/config.xml
# Ожидается: ::file:: (uuid инстанса — в атрибуте instance uuid="...")

# Сгенерировать новую пару: кнопка Generate Keypair в диалоге туннеля.
# Ключ сохраняется на диск при Save диалога.
```

> При обновлении с 2.x старый `private.key` автоматически переименовывается в `<uuid>.key` мигратором.

### Где искать логи

| Лог | Путь | Содержит |
|---|---|---|
| Плагин | `/var/log/amneziawg.log` | Все операции с временными метками |
| PHP ошибки | `/var/lib/php/tmp/PHP_errors.log` | Ошибки OPNsense PHP |
| Система | `/var/log/system/latest.log` | Ошибки configd |
| Alias-таблицы | `/var/log/aliastables/*.log` | Обновления URL Table aliases |

### Полезные команды

```bash
sh                                     # из csh в bash
awg show                               # статус всех туннелей, handshake
awg show awg0 transfer                 # переданные байты
configctl amneziawg status             # статус через configd (JSON)
configctl amneziawg version            # версия плагина
configctl amneziawg validate           # проверка корректности конфигов
configctl amneziawg ifstats awg1       # статистика конкретного туннеля
configctl amneziawg testconnect awg1   # тест связности конкретного туннеля
configctl amneziawg start_instance awg1   # поднять один туннель
configctl amneziawg stop_instance awg1    # погасить один туннель (watchdog не вернёт)
ifconfig awg0                          # детали интерфейса
netstat -rn | grep awg                 # таблица маршрутизации
tail -f /var/log/amneziawg.log         # мониторинг лога в реальном времени

pfctl -t vpn_domains -T show           # IP в alias
pfctl -t google_nets -T show           # CIDR в alias
pfctl -s state | grep awg0             # активные соединения через туннель
pfctl -s nat | grep awg0               # NAT-правила для туннеля

pkg info amnezia-kmod amnezia-tools    # версии пакетов
```

### Удаление плагина

```bash
sh install.sh uninstall
```

> При удалении: предлагается удалить пакеты `amnezia-kmod` и `amnezia-tools`, очищается запись `if_amn_load` из `/boot/loader.conf`. Директория `/usr/local/etc/amnezia/` (включая все `<uuid>.key` и `.conf` файлы) удаляется автоматически. Настройки туннелей в `config.xml` сохраняются и подхватываются при повторной установке (но приватные ключи придётся ввести заново).

---

## Структура файлов

```
plugin/
├── scripts/AmneziaWG/
│   ├── amneziawg-service-control.php     # Engine: start/stop/reconfigure/status/start_instance/stop_instance/sentinel_repair/gen_keypair
│   ├── amneziawg-watchdog.php            # Автоперезапуск упавших туннелей (per-instance)
│   ├── amneziawg-ifstats.php             # Статистика интерфейса (параметр awgN)
│   └── amneziawg-testconnect.php         # Тест связности + диагностика причины ошибки (параметр awgN)
├── service/conf/actions.d/
│   └── actions_amneziawg.conf            # Команды configd (15 действий)
├── etc/
│   ├── inc/plugins.inc.d/
│   │   └── amneziawg.inc                 # Регистрация сервиса в OPNsense + cron watchdog
│   └── newsyslog.conf.d/
│       └── amneziawg.conf                # Ротация лога (1MB / daily, 5 архивов, gzip)
└── mvc/app/
    ├── models/OPNsense/AmneziaWG/
    │   ├── General.xml / General.php      # Модель: флаги enabled, watchdog
    │   ├── Instance.xml / Instance.php    # Модель: туннели (ArrayField, UUID-ключи)
    │   ├── Migrations/M2_0_0.php          # Миграция flat 2.x → multi-instance
    │   ├── ACL/ACL.xml                    # ACL: права доступа к API и UI
    │   └── Menu/Menu.xml                  # Пункт меню VPN → AmneziaWG
    ├── controllers/OPNsense/AmneziaWG/
    │   ├── IndexController.php            # Рендеринг страницы (формы + грид)
    │   ├── Api/GeneralController.php      # API: get/set general flags
    │   ├── Api/InstanceController.php     # API: CRUD туннелей по UUID + genKeyPair (SEC-1/SEC-2)
    │   ├── Api/ServiceController.php      # API: сервис + start/stop_instance + diagnostics/testconnect
    │   ├── Api/ImportController.php       # API: парсинг .conf файла (POST only)
    │   └── forms/
    │       ├── general.xml                # Форма общих настроек
    │       └── dialogInstance.xml         # Диалог туннеля + колонки грида (один XML на оба)
    └── views/OPNsense/AmneziaWG/
        └── general.volt                   # Шаблон GUI (вкладки Tunnels / General / Diagnostics / Log)
```

**Файлы на OPNsense после установки:**
```
/usr/local/etc/amnezia/<uuid>.key           (0600) — приватный ключ туннеля (не в бэкапах), один на туннель
/usr/local/etc/amnezia/awg<N>.conf          (0600) — runtime-конфиги туннелей (зачищаются при stop)
/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt — версия плагина
/var/run/amneziawg.pid                             — PID sentinel-процесса (статус в дашборде)
/var/run/amneziawg.lock                            — lock файл от параллельных запусков
/var/run/amneziawg_stopped.flag                    — сервис остановлен вручную (watchdog не вмешивается)
/var/run/amneziawg_stopped_awg<N>.flag             — туннель остановлен per-row кнопкой из грида
/var/log/amneziawg.log                             — лог операций
```

---

## Архитектурные решения

**Table = off** — `awg-quick` не трогает таблицу маршрутизации. Маршрутами управляет OPNsense через Firewall Rules + Gateway. Это позволяет реализовать селективную маршрутизацию идентично Xray/WireGuard плагинам.

**Multi-instance (ArrayField)** — туннели хранятся в `//OPNsense/amneziawg/instances/instance` с UUID-ключами, до 100 туннелей `awg0`–`awg99` (уникальный `interface_number` на туннель). При обновлении с 2.x плоская модель мигрируется автоматически (`Migrations/M2_0_0.php`, запускается из `install.sh` через `run_migrations.php`).

**Sentinel приватных ключей** — в `config.xml` хранится строка `::file::` вместо ключа. Реальные ключи в `/usr/local/etc/amnezia/<uuid>.key` (0600, один на туннель). `InstanceController` перехватывает чтение/запись и управляет файлами напрямую. GUI показывает bullet-плейсхолдер; при удалении туннеля его ключ удаляется вместе с ним.

**Runtime-конфиги** записываются в `/usr/local/etc/amnezia/awg<N>.conf` (права 0600) при каждом старте туннеля и зачищаются при остановке сервиса — это производные артефакты, источник правды: `config.xml` + `<uuid>.key`.

**Sentinel-процесс** — `awg-quick up` завершается после создания интерфейса, поэтому для статуса в дашборде запускается легковесный процесс через `daemon -p /var/run/amneziawg.pid`. Один на сервис: работает, пока жив хотя бы один туннель.

**Watchdog (гранулярный)** — при падении туннеля перезапускает только его (`start_instance`), не трогая живые. Туннели, остановленные per-row кнопкой (флаг `amneziawg_stopped_awgN.flag`), не поднимает. Если умер только sentinel-процесс — чинит PID через `sentinel_repair` без перезапуска туннелей.

**flock защита** — параллельные вызовы `reconfigure` (от configd и ручного запуска) не конкурируют: второй вызов немедленно возвращает `OK` и выходит. Зависший дольше 120 секунд процесс-владелец убивается.

**AWG 2.0 — двойной html_entity_decode для I1-I5** — CPS-теги вида `<b 0xHEX>` хранятся в `config.xml` с двойным HTML-эскейпом из-за Phalcon-фильтра. Чтобы итоговая запись в `awg0.conf` содержала рабочие угловые скобки, плагин делает `html_entity_decode` дважды в `awg_get_instances()`.

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Автор и контрибьюторы

**Меркулов Павел Сергеевич** — оригинальный автор плагина (Февраль–Март 2026)

**Sergey Limonov** — поддержка AmneziaWG 2.0 (S3/S4, диапазоны H1-H4, I1-I5 CPS), документация по selective routing с CIDR-aliases и DoT (2026)

## Благодарности

- [AmneziaVPN](https://github.com/amnezia-vpn) — за разработку AmneziaWG
- [OPNsense](https://opnsense.org) — за открытую архитектуру плагинов
- [Toujifushiguro](https://github.com/Toujifushiguro) — за скрипт установки сервера
