#!/usr/bin/env bash
# =====================================================================================
# tests/mysql/run.sh — гарантии схемы и транзакционных алгоритмов на РЕАЛЬНОМ MySQL 8.0
#
#   tests/mysql/run.sh              # все сценарии
#   tests/mysql/run.sh S1 S7        # только сценарии с этими префиксами (+ итоговые инварианты S9)
#
# Переменные окружения:
#   UNIUNDATA_TEST_DB   имя тестовой БД (по умолчанию uniundata_test). БД ПЕРЕСОЗДАЁТСЯ.
#                       Имя обязано содержать "test" (защита от DROP боевой БД), иначе FORCE=1.
#   MYSQL_ARGS          параметры клиента mysql (по умолчанию: -uroot)
#   SCHEMA_FILE         схема (по умолчанию sql/schema.sql)
#   QUIET_TIMELINE=1    не печатать хронологии параллельных сессий
#
# Выход: 0 — все проверки PASS (EXPECTED-FAIL допускаются), 1 — есть FAIL, 2 — ошибка окружения.
# =====================================================================================
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
DB="${UNIUNDATA_TEST_DB:-uniundata_test}"
SCHEMA="${SCHEMA_FILE:-$ROOT/sql/schema.sql}"
read -r -a MYSQL_OPTS <<<"${MYSQL_ARGS:--uroot}"
MYSQL=(mysql "${MYSQL_OPTS[@]}" --default-character-set=utf8mb4)
FILTERS=("$@")

# Сессия как у плагина (Db::ensureSession): UTC, READ COMMITTED, lock wait timeout 5 c.
# SET NAMES — как $wpdb->set_charset() при DB_COLLATE = utf8mb4_unicode_520_ci.
INIT="SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci; SET time_zone = '+00:00'; \
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED; SET SESSION innodb_lock_wait_timeout = 5; \
SET SESSION group_concat_max_len = 1048576;"

PASS=0; FAIL=0; XFAIL=0; XPASS=0
FAILED=()
PIDS=()
SCN="-"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/uniundata-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# ------------------------------------------------------------------------------------
# Обвязка
# ------------------------------------------------------------------------------------
die() { echo "ОШИБКА ОКРУЖЕНИЯ: $*" >&2; exit 2; }

# Запрос в новой сессии; вывод без заголовков, через TAB.
q() { "${MYSQL[@]}" -N -B "$DB" -e "$INIT $1"; }

# Тело сессии с меткой (для t_trace / t_outcome / расшифровки data_lock_waits).
# $3 — «машина времени»: сессия живёт на N минут позже (SET TIMESTAMP влияет на UTC_TIMESTAMP()).
session_sql() {
  local scn=$1 name=$2 sql=$3 travel=${4:-0} pre
  pre="$INIT SET @scn = '$scn', @sess = '$name', @pause_at = NULL, @rv_at = NULL; CALL t_hello();"
  if [[ "$travel" != 0 ]]; then
    pre+=" SET TIMESTAMP = UNIX_TIMESTAMP(SYSDATE(6)) + ($travel) * 60;"
  fi
  printf '%s %s' "$pre" "$sql"
}

# run NAME SQL [travel] — сессия сценария на переднем плане; run_bg — в фоне.
run() {
  local name=$1 sql=$2 travel=${3:-0} out="$WORK/${SCN}.${1}.out"
  if ! "${MYSQL[@]}" -N -B "$DB" -e "$(session_sql "$SCN" "$name" "$sql" "$travel")" >>"$out" 2>&1; then
    echo "    !! сессия $name завершилась с ошибкой клиента:"; sed 's/^/       /' "$out"
  fi
}
run_bg() { run "$@" & PIDS+=($!); }
waitall() { local p; for p in "${PIDS[@]}"; do wait "$p"; done; PIDS=(); }

# Подготовка данных: отдельная метка, чтобы не попадать в хронологию сценария.
setup() {
  local sql=$1 travel=${2:-0} out="$WORK/setup.out"
  "${MYSQL[@]}" -N -B "$DB" -e "$(session_sql setup SETUP "$sql" "$travel")" >>"$out" 2>&1 \
    || { echo "    !! setup: $(tail -n 3 "$out")"; }
}

# Итог операции сессии: code / http / errno / поле JSON data.
oc()   { q "SELECT CONCAT(http, ' ', code) FROM t_outcome WHERE scn = '$SCN' AND sess = '$1' AND op = '$2' ORDER BY id DESC LIMIT 1"; }
oerr() { q "SELECT errno FROM t_outcome WHERE scn = '$SCN' AND sess = '$1' AND op = '$2' ORDER BY id DESC LIMIT 1"; }
oj()   { q "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$3')) FROM t_outcome WHERE scn = '$SCN' AND sess = '$1' AND op = '$2' ORDER BY id DESC LIMIT 1"; }
setup_oc() { q "SELECT CONCAT(http, ' ', code, IF(errno = 0, '', CONCAT(' errno ', errno, ' ', msg))) FROM t_outcome WHERE scn = 'setup' AND op = '$1' ORDER BY id DESC LIMIT 1"; }
# В трассе сценария есть строка, похожая на шаблон LIKE.
traced() { q "SELECT COUNT(*) > 0 FROM t_trace WHERE scn = '$SCN' AND sess = '$1' AND msg LIKE '$2'"; }
deadlocks() { q "SELECT COUNT(*) FROM t_outcome WHERE scn = '$SCN' AND errno IN (1213, 1205) AND retried = 0"; }
retried() { q "SELECT COUNT(*) FROM t_outcome WHERE scn = '$SCN' AND retried = 1"; }

ok()  { PASS=$((PASS + 1)); printf '  PASS   %-8s %s\n' "$1" "$2"; }
bad() {
  FAIL=$((FAIL + 1)); FAILED+=("$1")
  printf '  FAIL   %-8s %s\n           ожидалось: %s\n           получено:  %s\n' "$1" "$2" "$3" "$4"
}
# check ID "описание" "фактическое" "ожидаемое"
check() { if [[ "$3" == "$4" ]]; then ok "$1" "$2 [$3]"; else bad "$1" "$2" "$4" "$3"; fi; }
checkq() { check "$1" "$2" "$(q "$3" 2>&1)" "$4"; }
# Известный дефект (EXPECTED-FAIL): не валит прогон, но печатается; если вдруг прошёл — XPASS.
xcheck() {
  local id=$1 desc=$2 actual=$3 expected=$4 why=$5
  if [[ "$actual" == "$expected" ]]; then
    XPASS=$((XPASS + 1)); printf '  XPASS  %-8s %s [%s] — дефект больше не воспроизводится, снимите пометку\n' "$id" "$desc" "$actual"
  else
    XFAIL=$((XFAIL + 1))
    printf '  XFAIL  %-8s %s\n           ожидалось: %s; получено: %s\n           EXPECTED-FAIL (дефект схемы): %s\n' \
      "$id" "$desc" "$expected" "$actual" "$why"
  fi
}
# Вероятностный известный дефект: проявился → XFAIL с пояснением; не проявился в этом прогоне → INFO.
xflaky() {
  local id=$1 desc=$2 actual=$3 expected=$4 why=$5
  if [[ "$actual" == "$expected" ]]; then
    printf '  INFO   %-8s %s [%s] — вероятностный дефект в этом прогоне не проявился\n' "$id" "$desc" "$actual"
  else
    XFAIL=$((XFAIL + 1))
    printf '  XFAIL  %-8s %s\n           ожидалось: %s; получено: %s\n           EXPECTED-FAIL (дефект схемы): %s\n' \
      "$id" "$desc" "$expected" "$actual" "$why"
  fi
}
# Сводка последнего deadlock-а InnoDB: какие индексы и операторы участвовали.
last_deadlock() {
  "${MYSQL[@]}" -N -B -e "SHOW ENGINE INNODB STATUS" 2>/dev/null | sed 's/\\n/\n/g' \
    | sed -n '/LATEST DETECTED DEADLOCK/,/^TRANSACTIONS/p' \
    | grep -E '^(INSERT|SELECT|UPDATE|DELETE)|index .* of table|WE ROLL BACK' \
    | sed -E 's/RECORD LOCKS space id [0-9]+ page no [0-9]+ n bits [0-9]+ //; s/ trx id [0-9]+//' | sed 's/^/           │ /'
}
# expect_err ID "описание" ERRNO "имя ключа/ограничения" "SQL" — SQL обязан упасть именно так.
expect_err() {
  local id=$1 desc=$2 errno=$3 key=$4 sql=$5 out
  out=$("${MYSQL[@]}" -N -B "$DB" -e "$INIT $sql" 2>&1)
  if [[ "$out" == *"ERROR $errno"* && "$out" == *"$key"* ]]; then
    ok "$id" "$desc"; printf '           MySQL: %s\n' "$(grep -m1 '^ERROR' <<<"$out" | sed 's/ at line [0-9]*//')"
  else
    bad "$id" "$desc" "ERROR $errno … $key" "${out:-<успех, ошибки нет>}"
  fi
}

timeline() {
  [[ "${QUIET_TIMELINE:-0}" == 1 ]] && return 0
  echo "    ┌─ хронология $SCN (мс от первого шага; порядок — реальный порядок записи в t_trace)"
  q "SELECT CONCAT(LPAD(FORMAT(TIMESTAMPDIFF(MICROSECOND, (SELECT MIN(ts) FROM t_trace WHERE scn = '$SCN'), ts) / 1000, 1, 'en_US'), 9, ' '),
                   ' ms  ', RPAD(sess, 8, ' '), msg)
       FROM t_trace WHERE scn = '$SCN' ORDER BY id" | sed 's/^/    │/'
  echo "    └─"
}

section() { echo; echo "======================================================================================"; echo "$1  $2"; echo "======================================================================================"; }
sub() { SCN=$1; echo; echo "--- $1  $2"; }
want() {
  [[ ${#FILTERS[@]} -eq 0 ]] && return 0
  local f; for f in "${FILTERS[@]}"; do [[ "$1" == "$f"* ]] && return 0; done; return 1
}

mk_item() { q "CALL t_mk_item('$1', $2, @i); SELECT @i;"; }
uuid() { q "SELECT f_uuid4()"; }
cart_total() {
  q "SELECT COALESCE(SUM(ci.unit_price_amount), 0) FROM wp_book_cart_items ci JOIN wp_book_carts c ON c.id = ci.cart_id
      WHERE c.open_cart_user_id = $1 AND ci.status = 'active'"
}
# mk_order USER ITEM... → id заказа (резерв каждого экземпляра + checkout, последовательно)
mk_order() {
  local user=$1 it; shift
  for it in "$@"; do setup "CALL a_reserve($user, $it);"; done
  setup "CALL a_checkout($user, '$(uuid)', $(cart_total "$user"), 'EUR');"
  q "SELECT id FROM wp_book_orders WHERE user_id = $user ORDER BY id DESC LIMIT 1"
}
pp_of() { q "SELECT provider_payment_id FROM wp_book_payments WHERE order_id = $1 ORDER BY attempt_no DESC LIMIT 1"; }
price_of() { q "SELECT total_amount FROM wp_book_orders WHERE id = $1"; }
st() { q "SELECT availability_status FROM wp_book_items WHERE id = $1"; }
ost() { q "SELECT status FROM wp_book_orders WHERE id = $1"; }
# pass A по всем истёкшим к «сейчас + 61 мин» резервам: изоляция сценариев, где cron ставится на паузу
sweep() { setup "CALL a_expire_reservations(10000);" 61; }
start_gun() { q "SELECT ROUND(UNIX_TIMESTAMP(SYSDATE(6)) + $1, 6)"; }

# ------------------------------------------------------------------------------------
# Подготовка БД
# ------------------------------------------------------------------------------------
if [[ "$DB" != *test* && "${FORCE:-0}" != 1 ]]; then
  die "имя БД '$DB' не содержит 'test' — отказ пересоздавать (FORCE=1, если уверены)"
fi
[[ -r "$SCHEMA" ]] || die "нет файла схемы $SCHEMA"
command -v mysql >/dev/null || die "нет клиента mysql"
VERSION=$("${MYSQL[@]}" -N -B -e "SELECT VERSION()" 2>&1) || die "MySQL недоступен: $VERSION"
[[ "$VERSION" =~ ^8\. ]] || die "нужен MySQL 8.0+, сервер: $VERSION"
PS_ON=$("${MYSQL[@]}" -N -B -e "SELECT @@performance_schema") || die "нет доступа к performance_schema"
[[ "$PS_ON" == 1 ]] || die "performance_schema выключена: точки паузы не увидят ожидания блокировок"

echo "MySQL $VERSION, тестовая БД '$DB', схема $SCHEMA"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" \
  || die "не удалось создать БД $DB"
for f in "$SCHEMA" "$HERE/harness.sql" "$HERE/algorithms.sql" "$HERE/naive.sql" "$HERE/seed.sql" "$HERE/invariants.sql"; do
  "${MYSQL[@]}" "$DB" <"$f" || die "ошибка загрузки $f"
done
echo "загружено: schema.sql ($(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp\\_book\\_%'") таблиц wp_book_*), harness, algorithms, naive, seed, invariants"

# ======================================================================================
# S0. Гарантии схемы
# ======================================================================================
s0() {
  section S0 "Гарантии схемы: CHECK / UNIQUE / FK / generated columns / индексы"
  SCN=S0
  local i1 i2 i3 i4 r_x r_y c77 c78 o1
  i1=$(q "SELECT id FROM wp_book_items WHERE external_item_id = 'BASE-01'")
  i2=$(q "SELECT id FROM wp_book_items WHERE external_item_id = 'BASE-02'")
  i3=$(q "SELECT id FROM wp_book_items WHERE external_item_id = 'BASE-03'")
  i4=$(q "SELECT id FROM wp_book_items WHERE external_item_id = 'BASE-04'")
  local NOW='UTC_TIMESTAMP(6)' HOUR='UTC_TIMESTAMP(6) + INTERVAL 1 HOUR'
  local RES='INSERT INTO wp_book_reservations (book_item_id, user_id, reservation_status, attempt_no, reserved_at, expires_at, released_at, order_id)'

  echo "--- экземпляры"
  expect_err S0.01 "sold без sold_at невозможен (ck_items_sold_at)" 3819 ck_items_sold_at \
    "UPDATE wp_book_items SET availability_status = 'sold' WHERE id = $i1"
  expect_err S0.02 "неизвестный статус экземпляра (ck_items_status)" 3819 ck_items_status \
    "UPDATE wp_book_items SET availability_status = 'lost' WHERE id = $i1"
  expect_err S0.03 "внешний book_id = один экземпляр (uq_items_external)" 1062 uq_items_external \
    "INSERT INTO wp_book_items (book_record_id, source_name, external_item_id, price_amount)
     SELECT book_record_id, source_name, external_item_id, 100 FROM wp_book_items WHERE id = $i1"
  xcheck S0.04 "валюта только A–Z (ck_items_currency): 'eur' должна отклоняться" \
    "$(q "UPDATE wp_book_items SET currency = 'eur' WHERE id = $i1; SELECT currency FROM wp_book_items WHERE id = $i1;" 2>&1 | tail -n1)" \
    "ERROR 3819" \
    "колонка CHARACTER SET ascii (ascii_general_ci) — REGEXP '^[A-Z]{3}\$' регистронезависим; нужно REGEXP_LIKE(currency, '^[A-Z]{3}\$', 'c') или COLLATE ascii_bin"
  q "UPDATE wp_book_items SET currency = 'EUR' WHERE id = $i1"

  echo "--- резервы"
  q "$RES VALUES ($i2, 1, 'active', 1, $NOW, $HOUR, NULL, NULL)"
  expect_err S0.05 "второй active-резерв на экземпляр (uq_reservations_one_active_per_item)" 1062 uq_reservations_one_active_per_item \
    "$RES VALUES ($i2, 2, 'active', 1, $NOW, $HOUR, NULL, NULL)"
  checkq S0.06 "неактивных резервов на экземпляр — сколько угодно (generated column = NULL)" \
    "$RES VALUES ($i2, 3, 'expired', 1, $NOW, $HOUR, $NOW, NULL), ($i2, 4, 'cancelled', 1, $NOW, $HOUR, $NOW, NULL);
     SELECT CONCAT(COUNT(*), ' строк, active_book_item_id IS NULL: ', SUM(active_book_item_id IS NULL))
       FROM wp_book_reservations WHERE book_item_id = $i2 AND reservation_status <> 'active'" \
    "2 строк, active_book_item_id IS NULL: 2"
  expect_err S0.07 "4-я попытка (ck_reservations_attempt_range)" 3819 ck_reservations_attempt_range \
    "$RES VALUES ($i2, 3, 'expired', 4, $NOW, $HOUR, $NOW, NULL)"
  expect_err S0.08 "повтор номера попытки (uq_reservations_attempt)" 1062 uq_reservations_attempt \
    "$RES VALUES ($i2, 3, 'cancelled', 1, $NOW, $HOUR, $NOW, NULL)"
  expect_err S0.09 "released_by_admin обязан иметь attempt_no = NULL (ck_reservations_attempt_admin)" 3819 ck_reservations_attempt_admin \
    "$RES VALUES ($i3, 5, 'released_by_admin', 2, $NOW, $HOUR, $NOW, NULL)"
  expect_err S0.10 "не-admin резерв обязан иметь номер попытки (ck_reservations_attempt_admin)" 3819 ck_reservations_attempt_admin \
    "$RES VALUES ($i3, 5, 'active', NULL, $NOW, $HOUR, NULL, NULL)"
  expect_err S0.11 "active ⇔ released_at IS NULL (ck_reservations_released)" 3819 ck_reservations_released \
    "$RES VALUES ($i3, 5, 'active', 1, $NOW, $HOUR, $NOW, NULL)"
  expect_err S0.12 "converted_to_order без order_id (ck_reservations_order)" 3819 ck_reservations_order \
    "$RES VALUES ($i3, 5, 'converted_to_order', 1, $NOW, $HOUR, $NOW, NULL)"
  expect_err S0.13 "резерв несуществующего экземпляра (fk_reservations_item)" 1452 fk_reservations_item \
    "$RES VALUES (999999, 5, 'active', 1, $NOW, $HOUR, NULL, NULL)"
  q "UPDATE wp_book_reservations SET reservation_status = 'cancelled', released_at = $NOW, release_reason = 'test_cleanup'
      WHERE book_item_id = $i2 AND reservation_status = 'active'"

  echo "--- корзины"
  q "INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at) VALUES (77, 'active', $NOW, $NOW)"
  expect_err S0.14 "вторая открытая корзина пользователя (uq_carts_one_open_per_user)" 1062 uq_carts_one_open_per_user \
    "INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at) VALUES (77, 'checkout_started', $NOW, $NOW)"
  checkq S0.15 "закрытых корзин у пользователя — сколько угодно" \
    "INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at, closed_at)
     VALUES (77, 'expired', $NOW, $NOW, $NOW), (77, 'converted_to_order', $NOW, $NOW, $NOW);
     SELECT COUNT(*) FROM wp_book_carts WHERE user_id = 77" "3"
  q "INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at) VALUES (78, 'active', $NOW, $NOW)"
  c77=$(q "SELECT id FROM wp_book_carts WHERE open_cart_user_id = 77")
  c78=$(q "SELECT id FROM wp_book_carts WHERE open_cart_user_id = 78")
  r_x=$(q "SELECT id FROM wp_book_reservations WHERE book_item_id = $i2 AND user_id = 3")
  r_y=$(q "SELECT id FROM wp_book_reservations WHERE book_item_id = $i2 AND user_id = 4")
  q "INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at)
     VALUES ($c77, $i4, $r_x, 2500, 'EUR', 'active', $NOW, $HOUR)"
  expect_err S0.16 "книга — активная позиция только одной корзины (uq_cart_items_one_active_per_item)" 1062 uq_cart_items_one_active_per_item \
    "INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at)
     VALUES ($c78, $i4, $r_y, 2500, 'EUR', 'active', $NOW, $HOUR)"
  expect_err S0.17 "одна позиция на резерв (uq_cart_items_reservation)" 1062 uq_cart_items_reservation \
    "INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at, closed_at)
     VALUES ($c78, $i4, $r_x, 2500, 'EUR', 'removed', $NOW, $HOUR, $NOW)"
  q "UPDATE wp_book_cart_items SET status = 'removed', closed_at = $NOW WHERE cart_id = $c77"

  echo "--- заказы, платежи, события"
  local ORD='INSERT INTO wp_book_orders (public_order_id, user_id, checkout_request_id, status, currency, subtotal_amount, total_amount, customer_email, customer_first_name, customer_last_name, paid_at)'
  expect_err S0.18 "public_order_id только uniundata_<uuid v4> (ck_orders_public_id)" 3819 ck_orders_public_id \
    "$ORD VALUES ('uniundata_12345', 77, NULL, 'cancelled', 'EUR', 0, 0, 'a@example.test', 'A', 'B', NULL)"
  expect_err S0.19 "total = subtotal − discount + shipping (ck_orders_total)" 3819 ck_orders_total \
    "$ORD VALUES (CONCAT('uniundata_', f_uuid4()), 77, NULL, 'cancelled', 'EUR', 0, 100, 'a@example.test', 'A', 'B', NULL)"
  expect_err S0.20 "paid без paid_at (ck_orders_paid_at)" 3819 ck_orders_paid_at \
    "$ORD VALUES (CONCAT('uniundata_', f_uuid4()), 77, NULL, 'paid', 'EUR', 0, 0, 'a@example.test', 'A', 'B', NULL)"
  q "$ORD VALUES (CONCAT('uniundata_', f_uuid4()), 77, 'f00dfeed-0000-4000-8000-000000000001', 'cancelled', 'EUR', 0, 0, 'a@example.test', 'A', 'B', NULL)"
  expect_err S0.21 "один заказ на Idempotency-Key пользователя (uq_orders_checkout_request)" 1062 uq_orders_checkout_request \
    "$ORD VALUES (CONCAT('uniundata_', f_uuid4()), 77, 'f00dfeed-0000-4000-8000-000000000001', 'cancelled', 'EUR', 0, 0, 'a@example.test', 'A', 'B', NULL)"
  o1=$(q "SELECT id FROM wp_book_orders WHERE user_id = 77 LIMIT 1")
  expect_err S0.22 "в платеже только 4 последние цифры карты (ck_payments_last4)" 3819 ck_payments_last4 \
    "INSERT INTO wp_book_payments (order_id, provider, attempt_no, idempotency_key, status, amount, currency, card_last4)
     VALUES ($o1, 'testbank', 1, f_uuid4(), 'created', 0, 'EUR', '42x2')"
  q "INSERT INTO wp_book_payment_events (provider, provider_event_id, event_type, payload_redacted, payload_sha256, received_at)
     VALUES ('testbank', 'S0-evt', 'payment.succeeded', '{}', REPEAT('0', 64), $NOW)"
  expect_err S0.23 "одно событие банка — одна строка inbox (uq_payment_events_provider)" 1062 uq_payment_events_provider \
    "INSERT INTO wp_book_payment_events (provider, provider_event_id, event_type, payload_redacted, payload_sha256, received_at)
     VALUES ('testbank', 'S0-evt', 'payment.succeeded', '{}', REPEAT('0', 64), $NOW)"

}

# ======================================================================================
# S1. Два пользователя одновременно жмут «Отложить» один экземпляр
# ======================================================================================
s1() {
  section S1 "Два пользователя одновременно жмут «Отложить» один экземпляр"
  local it

  sub S1.1 "reserve с SELECT … FOR UPDATE: U2 ждёт блокировку строки экземпляра и видит reserved"
  it=$(mk_item S1-1 2500)
  run_bg U1 "SET @pause_at = 'reserve.after_item'; CALL a_reserve(1101, $it);"
  run_bg U2 "CALL t_wait_paused('U1', 'reserve.after_item'); CALL a_reserve(1102, $it);"
  waitall; timeline
  check S1.1a "U1 получил резерв" "$(oc U1 reserve)" "201 created"
  check S1.1b "U2 — 409 uniundata_item_unavailable" "$(oc U2 reserve)" "409 uniundata_item_unavailable"
  check S1.1c "U2 после ожидания прочитал availability_status" "$(oj U2 reserve '$.availability_status')" "reserved"
  check S1.1d "U2 действительно ждал X-lock строки wp_book_items (data_lock_waits)" \
    "$(traced U1 '%U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  checkq S1.1e "ровно один active-резерв на экземпляр, владелец U1" \
    "SELECT CONCAT(COUNT(*), ' / user ', MAX(user_id)) FROM wp_book_reservations WHERE book_item_id = $it AND reservation_status = 'active'" "1 / user 1101"
  checkq S1.1f "у U2 попытка не потрачена и корзина не создана (откат целиком)" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_reservations WHERE user_id = 1102), '/', (SELECT COUNT(*) FROM wp_book_carts WHERE user_id = 1102))" "0/0"
  check S1.1g "экземпляр reserved" "$(st "$it")" "reserved"

  sub S1.2 "без FOR UPDATE (наивный код): второй рубеж — UNIQUE uq_reservations_one_active_per_item"
  it=$(mk_item S1-2 2500)
  run_bg U1 "SET @pause_at = 'naive.before_commit'; CALL n_reserve(1103, $it);"
  run_bg U2 "CALL t_wait_paused('U1', 'naive.before_commit'); CALL n_reserve(1104, $it);"
  waitall; timeline
  check S1.2a "U1 создал резерв" "$(oc U1 naive_reserve)" "201 created"
  check S1.2b "U2 прочитал available обычным SELECT (гонка воспроизведена)" \
    "$(traced U2 '%обычный SELECT экземпляра%→ available%')" "1"
  check S1.2c "U2 получил ERROR 1062 и откат" "$(oerr U2 naive_reserve)" "1062"
  check S1.2d "1062 именно по uq_reservations_one_active_per_item → 409 item_unavailable" \
    "$(q "SELECT CONCAT(http, ' ', code, ' | ', msg) FROM t_outcome WHERE scn = 'S1.2' AND sess = 'U2'")" \
    "409 uniundata_item_unavailable | Duplicate entry '$it' for key 'wp_book_reservations.uq_reservations_one_active_per_item'"
  checkq S1.2e "в БД один active-резерв" \
    "SELECT COUNT(*) FROM wp_book_reservations WHERE book_item_id = $it AND reservation_status = 'active'" "1"

  sub S1.3 "один пользователь, двойной клик (две вкладки): идемпотентно, попытка не тратится"
  it=$(mk_item S1-3 2500)
  run_bg T1 "SET @pause_at = 'reserve.before_commit'; CALL a_reserve(1105, $it);"
  run_bg T2 "CALL t_wait_paused('T1', 'reserve.before_commit'); CALL a_reserve(1105, $it);"
  waitall; timeline
  check S1.3a "первый запрос — 201" "$(oc T1 reserve)" "201 created"
  check S1.3b "второй — 200 с тем же резервом" "$(oc T2 reserve) #$(oj T2 reserve '$.reservation_id')" "200 existing #$(oj T1 reserve '$.reservation_id')"
  check S1.3c "второй ждал на корзине пользователя (1-й уровень порядка блокировок)" \
    "$(traced T1 '%T2 ждёт X,REC_NOT_GAP wp_book_carts.%')" "1"
  checkq S1.3d "одна попытка, одна позиция корзины, одна корзина" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_reservations WHERE user_id = 1105), '/',
                   (SELECT COUNT(*) FROM wp_book_cart_items WHERE book_item_id = $it), '/',
                   (SELECT COUNT(*) FROM wp_book_carts WHERE user_id = 1105))" "1/1/1"

  sub S1.4 "стресс: 8 пользователей одновременно (стартовый пистолет), без пауз"
  it=$(mk_item S1-4 2500)
  local t0 u; t0=$(start_gun 0.7)
  for u in 1 2 3 4 5 6 7 8; do run_bg "P$u" "CALL t_start_at($t0); CALL a_reserve($((1110 + u)), $it);"; done
  waitall
  checkq S1.4a "ровно один 201, семь 409 item_unavailable" \
    "SELECT GROUP_CONCAT(CONCAT(n, '×', http, ' ', code) ORDER BY http) FROM
       (SELECT http, code, COUNT(*) n FROM t_outcome WHERE scn = 'S1.4' AND op = 'reserve' GROUP BY http, code) x" \
    "1×201 created,7×409 uniundata_item_unavailable"
  check S1.4b "ни одного deadlock / lock wait timeout" "$(deadlocks)" "0"
  checkq S1.4c "в БД один active-резерв" \
    "SELECT COUNT(*) FROM wp_book_reservations WHERE book_item_id = $it AND reservation_status = 'active'" "1"
  s1_5
}

# S1.5 — отдельной функцией, чтобы вызываться из s1
s1_5() {
  sub S1.5 "стресс: 12 соседних экземпляров только что освобождены, 12 других пользователей резервируют одновременно"
  local items=() k t0
  for k in $(seq 1 12); do
    items+=("$(mk_item "S1-5-$k" 1500)")
    setup "CALL a_reserve($((1150 + k)), ${items[$((k - 1))]}); CALL a_remove($((1150 + k)), ${items[$((k - 1))]});"
  done
  t0=$(start_gun 1.0)
  for k in $(seq 1 12); do
    run_bg "R$k" "CALL t_start_at($t0); CALL t_retry('CALL a_reserve($((1170 + k)), ${items[$((k - 1))]})', 'reserve');"
  done
  waitall
  checkq S1.5a "все 12 резервов созданы (с повтором как Db::transaction)" \
    "SELECT COUNT(*) FROM t_outcome WHERE scn = 'S1.5' AND op = 'reserve' AND code = 'created' AND retried = 0" "12"
  check S1.5b "итог: ни одной операции, упавшей по deadlock / lock wait timeout" "$(deadlocks)" "0"
  xflaky S1.5c "без повтора: ни одного deadlock" "$(retried) повторено" "0 повторено" \
    "повторная вставка active-резерва проверяет дубликат по delete-marked записи в uq_reservations_one_active_per_item / uq_cart_items_one_active_per_item (generated columns) с next-key/gap-блокировками даже в READ COMMITTED"
  if [[ "$(retried)" != 0 ]]; then echo "           последний deadlock InnoDB:"; last_deadlock; fi
}

# ======================================================================================
# S2. Пользователь не оплатил за час
# ======================================================================================
s2() {
  section S2 "Пользователь не оплатил за час"
  local it r c o

  sub S2.1 "резерв без checkout: pass A через 59 мин ничего не делает, через 61 мин освобождает"
  sweep
  it=$(mk_item S2-1 3000)
  run U1 "CALL a_reserve(1201, $it);"
  r=$(oj U1 reserve '$.reservation_id'); c=$(oj U1 reserve '$.cart_id')
  run CRON59 "CALL a_expire_reservations(200);" 59
  check S2.1a "+59 мин: резерв ещё active, экземпляр reserved" \
    "$(q "SELECT reservation_status FROM wp_book_reservations WHERE id = $r")/$(st "$it")" "active/reserved"
  run CRON61 "CALL a_expire_reservations(200);" 61
  timeline
  checkq S2.1b "+61 мин: резерв expired (release_reason expired), released_at заполнен" \
    "SELECT CONCAT(reservation_status, '/', release_reason, '/', released_at IS NOT NULL) FROM wp_book_reservations WHERE id = $r" "expired/expired/1"
  checkq S2.1c "позиция корзины expired, корзина закрыта как expired (последняя позиция)" \
    "SELECT CONCAT((SELECT status FROM wp_book_cart_items WHERE reservation_id = $r), '/', (SELECT status FROM wp_book_carts WHERE id = $c))" "expired/expired"
  check S2.1d "экземпляр снова available" "$(st "$it")" "available"
  run U2 "CALL a_reserve(1202, $it);"
  check S2.1e "другой пользователь сразу может отложить" "$(oc U2 reserve)" "201 created"
  checkq S2.1f "попытка U1 засчитана (attempt_no сохранён)" \
    "SELECT attempt_no FROM wp_book_reservations WHERE id = $r" "1"

  sub S2.2 "checkout начат, оплаты нет: pass A не трогает, pass B после payment_due_at (40 мин) освобождает"
  sweep
  it=$(mk_item S2-2 3100)
  o=$(mk_order 1203 "$it")
  check S2.2a "заказ создан, pending_payment, экземпляр checkout_pending" "$(ost "$o")/$(st "$it")" "pending_payment/checkout_pending"
  run CRONA "CALL a_expire_reservations(200);" 61
  check S2.2b "pass A (+61 мин): резерв уже converted_to_order — экземпляр не тронут" "$(st "$it")" "checkout_pending"
  run CRONB39 "CALL a_expire_order_one($o, 'pending');" 39
  check S2.2c "pass B (+39 мин, до payment_due_at): noop" "$(oc CRONB39 expire_order)/$(ost "$o")" "200 noop/pending_payment"
  run CRONB41 "CALL a_expire_orders('pending');" 41
  timeline
  check S2.2d "pass B (+41 мин): заказ payment_expired" "$(ost "$o")" "payment_expired"
  checkq S2.2e "платёж expired, экземпляр available" \
    "SELECT CONCAT((SELECT status FROM wp_book_payments WHERE order_id = $o), '/', (SELECT availability_status FROM wp_book_items WHERE id = $it))" "expired/available"

  sub S2.3 "checkout после истечения часа, но раньше cron: позиция истекает в самом checkout"
  it=$(mk_item S2-3 3200)
  setup "CALL a_reserve(1204, $it);"
  run U1 "CALL a_checkout(1204, '$(uuid)', 3200, 'EUR');" 61
  timeline
  check S2.3a "409 uniundata_cart_changed (positions_expired)" "$(oc U1 checkout) $(oj U1 checkout '$.reason')" "409 uniundata_cart_changed positions_expired"
  checkq S2.3b "заказ не создан, резерв expired (checkout_expired), экземпляр available" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_orders WHERE user_id = 1204), '/',
                   (SELECT CONCAT(reservation_status, ':', release_reason) FROM wp_book_reservations WHERE user_id = 1204), '/',
                   (SELECT availability_status FROM wp_book_items WHERE id = $it))" "0/expired:checkout_expired/available"

  sub S2.4 "гонка: pass A освобождает ↔ другой пользователь в этот момент жмёт «Отложить»"
  sweep
  it=$(mk_item S2-4 3300)
  setup "CALL a_reserve(1205, $it);"
  run_bg CRON "SET @pause_at = 'expA.before_commit'; CALL a_expire_reservations(200);" 61
  run_bg U2 "CALL t_wait_paused('CRON', 'expA.before_commit'); CALL a_reserve(1206, $it);"
  waitall; timeline
  check S2.4a "U2 ждал X-lock экземпляра, удерживаемый cron" "$(traced CRON '%U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  check S2.4b "после COMMIT cron U2 получил резерв" "$(oc U2 reserve)" "201 created"
  checkq S2.4c "старый резерв expired, новый active у U2" \
    "SELECT GROUP_CONCAT(CONCAT(user_id, ':', reservation_status) ORDER BY id) FROM wp_book_reservations WHERE book_item_id = $it" "1205:expired,1206:active"
}

# ======================================================================================
# S3. Три резерва без покупки → четвёртая попытка отклонена
# ======================================================================================
s3() {
  section S3 "Три резерва без покупки → 4-я попытка отклонена; admin-release не считается"
  local it r

  sub S3.1 "1-я: удалил из корзины; 2-я: истекла (pass A); 3-я: удалил; 4-я — 409"
  sweep
  it=$(mk_item S3-1 4000)
  run U1 "CALL a_reserve(1301, $it); CALL a_remove(1301, $it); CALL a_reserve(1301, $it);"
  run CRON "CALL a_expire_reservations(200);" 61
  run U1 "CALL a_reserve(1301, $it); CALL a_remove(1301, $it); CALL a_reserve(1301, $it);"
  timeline
  checkq S3.1a "история: попытки 1..3 (cancelled, expired, cancelled)" \
    "SELECT GROUP_CONCAT(CONCAT(attempt_no, ':', reservation_status) ORDER BY id) FROM wp_book_reservations WHERE user_id = 1301" \
    "1:cancelled,2:expired,3:cancelled"
  check S3.1b "4-я попытка — 409 uniundata_reservation_limit_reached (COUNT под блокировкой)" "$(oc U1 reserve)" "409 uniundata_reservation_limit_reached"
  check S3.1c "data.used = 3" "$(oj U1 reserve '$.used')" "3"
  checkq S3.1d "4-я строка не создана, экземпляр available" \
    "SELECT CONCAT(COUNT(*), '/', (SELECT availability_status FROM wp_book_items WHERE id = $it)) FROM wp_book_reservations WHERE user_id = 1301" "3/available"
  run U2 "CALL a_reserve(1302, $it);"
  check S3.1e "лимит персональный: другой пользователь резервирует" "$(oc U2 reserve) attempt $(oj U2 reserve '$.attempt_no')" "201 created attempt 1"

  sub S3.2 "второй рубеж: код без проверки лимита упирается в CHECK ck_reservations_attempt_range"
  run U1 "CALL a_remove(1302, $it); CALL n_reserve(1301, $it);"
  check S3.2a "наивный INSERT attempt_no = 4 → ERROR 3819 → 409 limit_reached" \
    "$(q "SELECT CONCAT(errno, ' ', http, ' ', code) FROM t_outcome WHERE scn = 'S3.2' AND op = 'naive_reserve'")" \
    "3819 409 uniundata_reservation_limit_reached"
  checkq S3.2b "в БД по-прежнему 3 попытки" "SELECT COUNT(*) FROM wp_book_reservations WHERE user_id = 1301 AND book_item_id = $it" "3"

  sub S3.3 "admin-release возвращает попытку: attempt_no := NULL"
  it=$(mk_item S3-3 4100)
  run U1 "CALL a_reserve(1303, $it); CALL a_remove(1303, $it); CALL a_reserve(1303, $it); CALL a_remove(1303, $it); CALL a_reserve(1303, $it);"
  r=$(oj U1 reserve '$.reservation_id')
  run ADMIN "CALL a_admin_release(9001, $r);"
  check S3.3a "менеджер снял 3-й резерв" "$(oc ADMIN admin_release)" "200 released"
  checkq S3.3b "резерв released_by_admin, attempt_no = NULL" \
    "SELECT CONCAT(reservation_status, '/', COALESCE(attempt_no, 'NULL')) FROM wp_book_reservations WHERE id = $r" "released_by_admin/NULL"
  run U1 "CALL a_reserve(1303, $it);"
  check S3.3c "пользователь снова может отложить — это попытка 3" "$(oc U1 reserve) attempt $(oj U1 reserve '$.attempt_no')" "201 created attempt 3"
  run U1 "CALL a_remove(1303, $it); CALL a_reserve(1303, $it);"
  check S3.3d "после трёх засчитанных попыток — 409" "$(oc U1 reserve)" "409 uniundata_reservation_limit_reached"
  checkq S3.3e "история попыток" \
    "SELECT GROUP_CONCAT(CONCAT(COALESCE(attempt_no, 'NULL'), ':', reservation_status) ORDER BY id) FROM wp_book_reservations WHERE user_id = 1303" \
    "1:cancelled,2:cancelled,NULL:released_by_admin,3:cancelled"
}

# ======================================================================================
# S4. Удаление из корзины
# ======================================================================================
s4() {
  section S4 "Пользователь удалил книгу из корзины"
  local a b c o1 o2 ok

  sub S4.1 "удаление: резерв cancelled, позиция removed, экземпляр available; повтор идемпотентен"
  a=$(mk_item S4-1A 5000); b=$(mk_item S4-1B 5100)
  run U1 "CALL a_reserve(1401, $a); DO SLEEP(0.01); CALL a_reserve(1401, $b); CALL a_remove(1401, $a);"
  timeline
  check S4.1a "remove-item → 200 removed, экземпляр available" "$(oc U1 remove) $(oj U1 remove '$.item_status')" "200 removed available"
  checkq S4.1b "резерв cancelled (user_removed), позиция removed" \
    "SELECT CONCAT(r.reservation_status, ':', r.release_reason, '/', ci.status) FROM wp_book_reservations r
       JOIN wp_book_cart_items ci ON ci.reservation_id = r.id WHERE r.user_id = 1401 AND r.book_item_id = $a" "cancelled:user_removed/removed"
  checkq S4.1c "корзина открыта, expires_at = срок оставшейся книги" \
    "SELECT CONCAT(c.status, '/', c.expires_at = ci.expires_at) FROM wp_book_carts c JOIN wp_book_cart_items ci ON ci.cart_id = c.id
      WHERE c.open_cart_user_id = 1401 AND ci.book_item_id = $b AND ci.status = 'active'" "active/1"
  run U1 "CALL a_remove(1401, $a);"
  check S4.1d "повторное удаление — 200 already_removed" "$(oc U1 remove)" "200 already_removed"
  run U1 "CALL a_remove(1401, $b); CALL a_reserve(1401, $a);"
  checkq S4.1e "пустая корзина остаётся открытой; повторный резерв той же книги — попытка 2 в той же корзине" \
    "SELECT CONCAT(c.status, '/', r.attempt_no, '/', (SELECT COUNT(*) FROM wp_book_cart_items WHERE cart_id = c.id AND book_item_id = $a))
       FROM wp_book_carts c JOIN wp_book_reservations r ON r.cart_id = c.id AND r.reservation_status = 'active'
      WHERE c.open_cart_user_id = 1401" "active/2/2"

  sub S4.2 "гонка remove ↔ checkout: remove первым → checkout видит изменённую корзину (409)"
  a=$(mk_item S4-2A 5200); b=$(mk_item S4-2B 5300)
  setup "CALL a_reserve(1402, $a); CALL a_reserve(1402, $b);"
  run_bg R "SET @pause_at = 'remove.before_commit'; CALL a_remove(1402, $a);"
  run_bg CO "CALL t_wait_paused('R', 'remove.before_commit'); CALL a_checkout(1402, '$(uuid)', 10500, 'EUR');"
  waitall; timeline
  check S4.2a "remove — 200" "$(oc R remove)" "200 removed"
  check S4.2b "checkout ждал корзину и ответил 409 cart_changed (total_mismatch: 5300 вместо 10500)" \
    "$(oc CO checkout) $(oj CO checkout '$.reason') $(oj CO checkout '$.actual_total_amount')" "409 uniundata_cart_changed total_mismatch 5300"
  checkq S4.2c "заказа нет; A available, B reserved" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_orders WHERE user_id = 1402), '/', (SELECT availability_status FROM wp_book_items WHERE id = $a), '/', (SELECT availability_status FROM wp_book_items WHERE id = $b))" "0/available/reserved"

  sub S4.3 "гонка remove ↔ checkout: checkout первым → remove получает 409 (книга уже в заказе)"
  a=$(mk_item S4-3A 5400); b=$(mk_item S4-3B 5500)
  setup "CALL a_reserve(1403, $a); CALL a_reserve(1403, $b);"
  run_bg CO "SET @pause_at = 'checkout.before_commit'; CALL a_checkout(1403, '$(uuid)', 10900, 'EUR');"
  run_bg R "CALL t_wait_paused('CO', 'checkout.before_commit'); CALL a_remove(1403, $a);"
  waitall; timeline
  check S4.3a "checkout — 201" "$(oc CO checkout)" "201 created"
  check S4.3b "remove ждал корзину, затем 409 cart_changed (item_in_order)" "$(oc R remove) $(oj R remove '$.reason')" "409 uniundata_cart_changed item_in_order"
  check S4.3c "экземпляры A и B — checkout_pending" "$(st "$a")/$(st "$b")" "checkout_pending/checkout_pending"
}

# ======================================================================================
# S5. Оплата прошла, webhook пришёл дважды
# ======================================================================================
s5() {
  section S5 "Оплата прошла, webhook пришёл дважды"
  local it o pp p upd1 upd2 price

  sub S5.1 "повтор того же события ПОСЛЕ обработки: inbox отвечает duplicate, ничего не меняется"
  it=$(mk_item S5-1 6000); o=$(mk_order 1501 "$it"); pp=$(pp_of "$o")
  run WH1 "CALL a_webhook('S5-1-evt', '$pp', 'succeeded', 6000, 'EUR', NULL);"
  upd1=$(q "SELECT CONCAT(updated_at, '|', (SELECT updated_at FROM wp_book_items WHERE id = $it)) FROM wp_book_orders WHERE id = $o")
  run WH2 "CALL a_webhook('S5-1-evt', '$pp', 'succeeded', 6000, 'EUR', NULL);"
  upd2=$(q "SELECT CONCAT(updated_at, '|', (SELECT updated_at FROM wp_book_items WHERE id = $it)) FROM wp_book_orders WHERE id = $o")
  timeline
  check S5.1a "первый — processed, второй — duplicate (inbox)" "$(oc WH1 webhook) / $(oc WH2 webhook) $(oj WH2 webhook '$.where')" "200 processed / 200 duplicate inbox"
  checkq S5.1b "одна продажа, заказ paid, платёж succeeded, экземпляр sold" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it), '/', (SELECT status FROM wp_book_orders WHERE id = $o), '/',
                   (SELECT status FROM wp_book_payments WHERE order_id = $o), '/', (SELECT availability_status FROM wp_book_items WHERE id = $it))" "1/paid/succeeded/sold"
  checkq S5.1c "одна строка события: processed, attempts = 2" \
    "SELECT CONCAT(COUNT(*), '/', MAX(processing_status), '/', MAX(attempts)) FROM wp_book_payment_events WHERE provider_event_id = 'S5-1-evt'" "1/processed/2"
  check S5.1d "повтор не тронул строки заказа и экземпляра (updated_at не изменился)" "$upd2" "$upd1"
  checkq S5.1e "переход заказа в paid и экземпляра в sold записан в аудит один раз" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_audit_log WHERE entity_type = 'order' AND entity_id = $o AND to_status = 'paid'), '/',
                   (SELECT COUNT(*) FROM wp_book_audit_log WHERE entity_type = 'item' AND entity_id = $it AND to_status = 'sold'))" "1/1"

  sub S5.2 "то же событие ОДНОВРЕМЕННО двумя сессиями: второй ждёт блокировку и видит processed"
  it=$(mk_item S5-2 6100); o=$(mk_order 1502 "$it"); pp=$(pp_of "$o")
  run_bg WH1 "SET @pause_at = 'apply.after_items'; CALL a_webhook('S5-2-evt', '$pp', 'succeeded', 6100, 'EUR', NULL);"
  run_bg WH2 "CALL t_wait_paused('WH1', 'apply.after_items'); CALL a_webhook('S5-2-evt', '$pp', 'succeeded', 6100, 'EUR', NULL);"
  waitall; timeline
  check S5.2a "оба прошли inbox (received), WH2 ждал X-lock экземпляра" "$(traced WH1 '%WH2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  check S5.2b "WH1 processed, WH2 duplicate (обнаружен под блокировкой события)" "$(oc WH1 webhook) / $(oc WH2 webhook) $(oj WH2 webhook '$.where')" "200 processed / 200 duplicate under_lock"
  checkq S5.2c "одна продажа, одна строка события processed (attempts 2), paid в аудите один раз" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it), '/',
                   (SELECT CONCAT(COUNT(*), ':', MAX(processing_status), ':', MAX(attempts)) FROM wp_book_payment_events WHERE provider_event_id = 'S5-2-evt'), '/',
                   (SELECT COUNT(*) FROM wp_book_audit_log WHERE entity_type = 'order' AND entity_id = $o AND to_status = 'paid'))" "1/1:processed:2/1"

  sub S5.3 "банк прислал ДВА РАЗНЫХ события об одном успехе одновременно"
  it=$(mk_item S5-3 6200); o=$(mk_order 1503 "$it"); pp=$(pp_of "$o")
  run_bg WH1 "SET @pause_at = 'apply.before_commit'; CALL a_webhook('S5-3-evt-A', '$pp', 'succeeded', 6200, 'EUR', NULL);"
  run_bg WH2 "CALL t_wait_paused('WH1', 'apply.before_commit'); CALL a_webhook('S5-3-evt-B', '$pp', 'succeeded', 6200, 'EUR', NULL);"
  waitall; timeline
  check S5.3a "A processed; B — ignored (already_succeeded)" "$(oc WH1 webhook) / $(oc WH2 webhook) $(oj WH2 webhook '$.note')" "200 processed / 200 ignored already_succeeded"
  checkq S5.3b "одна продажа; события: A processed, B ignored" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it), '/',
                   (SELECT GROUP_CONCAT(processing_status ORDER BY provider_event_id) FROM wp_book_payment_events WHERE provider_event_id LIKE 'S5-3-evt-%'))" "1/processed,ignored"

  sub S5.4 "без блокировок и inbox (наивный обработчик): второй рубеж — UNIQUE uq_sales_book_item"
  it=$(mk_item S5-4 6300); o=$(mk_order 1504 "$it"); p=$(q "SELECT id FROM wp_book_payments WHERE order_id = $o")
  run_bg N1 "SET @pause_at = 'nsell.before_commit'; CALL n_sell($o, $it, $p);"
  run_bg N2 "CALL t_wait_paused('N1', 'nsell.before_commit'); CALL n_sell($o, $it, $p);"
  waitall; timeline
  check S5.4a "N2 прочитал checkout_pending обычным SELECT (гонка воспроизведена)" "$(traced N2 '%→ checkout_pending%')" "1"
  check S5.4b "N2 — ERROR 1062 по uq_sales_book_item" \
    "$(q "SELECT CONCAT(errno, ' | ', msg) FROM t_outcome WHERE scn = 'S5.4' AND sess = 'N2'")" \
    "1062 | Duplicate entry '$it' for key 'wp_book_sales.uq_sales_book_item'"
  checkq S5.4c "продажа одна" "SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it" "1"
  # Наивный обработчик продал экземпляр, не трогая заказ/платёж: приводим к согласованному виду для S9.
  q "UPDATE wp_book_payments SET status = 'succeeded', succeeded_at = UTC_TIMESTAMP(6) WHERE order_id = $o;
     UPDATE wp_book_orders SET status = 'paid', paid_at = UTC_TIMESTAMP(6) WHERE id = $o"

  sub S5.5 "второй УСПЕШНЫЙ платёж по уже оплаченному заказу (две вкладки банка)"
  it=$(mk_item S5-5 6400); o=$(mk_order 1505 "$it"); pp=$(pp_of "$o")
  # Вторая платёжная попытка, как её создал бы POST /orders/{id}/pay до прихода первого webhook
  setup "INSERT INTO wp_book_payments (order_id, provider, attempt_no, idempotency_key, provider_payment_id, status, amount, currency)
         VALUES ($o, 'testbank', 2, f_uuid4(), 'pp_S5-5-second', 'pending', 6400, 'EUR');"
  run WH1 "CALL a_webhook('S5-5-evt-1', '$pp', 'succeeded', 6400, 'EUR', NULL);"
  run WH2 "CALL a_webhook('S5-5-evt-2', 'pp_S5-5-second', 'succeeded', 6400, 'EUR', NULL);"
  check S5.5a "второй платёж принят (succeeded), заказ помечен duplicate_payment" \
    "$(oj WH2 webhook '$.note')/$(q "SELECT CONCAT(status, ':', needs_attention, ':', attention_reason) FROM wp_book_orders WHERE id = $o")" "duplicate_payment/paid:1:duplicate_payment"
  check S5.5b "в очередь поставлен возврат второго платежа на полную сумму" "$(oj WH2 webhook '$.refund_enqueued.amount') $(oj WH2 webhook '$.refund_enqueued.reason')" "6400 duplicate_payment"
  checkq S5.5c "экземпляр продан один раз" "SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it" "1"
}

# ======================================================================================
# S6. Синхронизация получила ранее проданную книгу повторно
# ======================================================================================
s6() {
  section S6 "Синхронизация получила ранее проданную книгу повторно"
  local sold avail o pp J it

  sub S6.1 "sold не перетирается, items_conflicts++, цена проданного не меняется"
  sold=$(mk_item S6-SOLD 7000); avail=$(mk_item S6-AVAIL 7100)
  o=$(mk_order 1601 "$sold"); pp=$(pp_of "$o")
  setup "CALL a_webhook('S6-1-evt', '$pp', 'succeeded', 7000, 'EUR', NULL);"
  check S6.1a "исходно экземпляр продан" "$(st "$sold")" "sold"
  J='[{"external_item_id":"S6-SOLD","status":"present","price_amount":7900,"currency":"EUR"},
      {"external_item_id":"S6-AVAIL","status":"present","price_amount":7500,"currency":"EUR"},
      {"external_item_id":"S6-NEW","status":"present","price_amount":1200,"currency":"EUR"}]'
  run SYNC "CALL a_sync_start('primary', @r); CALL a_sync_batch(@r, 'primary', '$J'); CALL a_sync_finish(@r, 'primary');"
  timeline
  checkq S6.1b "проданный: sold, sold_at сохранён, цена прежняя (7000), source_status present" \
    "SELECT CONCAT(availability_status, '/', sold_at IS NOT NULL, '/', price_amount, '/', source_status) FROM wp_book_items WHERE id = $sold" "sold/1/7000/present"
  check S6.1c "обычный экземпляр обновлён (цена 7500), новый создан" "$(q "SELECT price_amount FROM wp_book_items WHERE id = $avail")/$(q "SELECT availability_status FROM wp_book_items WHERE external_item_id = 'S6-NEW'")" "7500/available"
  checkq S6.1d "журнал прогона: conflicts=1, created=1, updated=2, ошибка conflict_sold (errors_count не растёт)" \
    "SELECT CONCAT('conflicts=', items_conflicts, ' created=', items_created, ' updated=', items_updated, ' errors=', errors_count, ' ',
                   CONVERT(JSON_UNQUOTE(JSON_EXTRACT(error_log, '\$[0].code')) USING utf8mb4) COLLATE utf8mb4_unicode_520_ci, ' status=', status)
       FROM wp_book_sync_runs WHERE id = (SELECT MAX(id) FROM wp_book_sync_runs)" "conflicts=1 created=1 updated=2 errors=0 conflict_sold status=succeeded"
  checkq S6.1e "продажа не тронута" "SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $sold" "1"
  run SYNC2 "CALL a_sync_start('primary', @r); CALL a_sync_batch(@r, 'primary', '$J'); CALL a_sync_finish(@r, 'primary');"
  checkq S6.1f "повторный прогон того же пакета: sold на месте, конфликт снова посчитан, остальное skipped" \
    "SELECT CONCAT((SELECT availability_status FROM wp_book_items WHERE id = $sold), ' conflicts=', items_conflicts, ' skipped=', items_skipped, ' created=', items_created)
       FROM wp_book_sync_runs WHERE id = (SELECT MAX(id) FROM wp_book_sync_runs)" "sold conflicts=1 skipped=3 created=0"

  sub S6.2 "синхронизация одновременно с «Отложить»: источник снял книгу, а её резервируют"
  it=$(mk_item S6-RES 7200)
  J='[{"external_item_id":"S6-RES","status":"withdrawn","price_amount":7200,"currency":"EUR"}]'
  run_bg U1 "SET @pause_at = 'reserve.before_commit'; CALL a_reserve(1602, $it);"
  run_bg SYNC "CALL t_wait_paused('U1', 'reserve.before_commit'); CALL a_sync_start('primary', @r); CALL a_sync_batch(@r, 'primary', '$J'); CALL a_sync_finish(@r, 'primary');"
  waitall; timeline
  check S6.2a "sync ждал X-lock экземпляра, который держит reserve" "$(traced U1 '%SYNC ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  checkq S6.2b "reserved не перетёрт; source_status = withdrawn; conflicts = 1" \
    "SELECT CONCAT(i.availability_status, '/', i.source_status, '/', r.items_conflicts) FROM wp_book_items i, wp_book_sync_runs r
      WHERE i.id = $it AND r.id = (SELECT MAX(id) FROM wp_book_sync_runs)" "reserved/withdrawn/1"
  run U1 "CALL a_remove(1602, $it);"
  check S6.2c "после удаления из корзины экземпляр получает release target = withdrawn" "$(oj U1 remove '$.item_status')/$(st "$it")" "withdrawn/withdrawn"

  sub S6.3 "синхронизация первой: «Отложить» ждёт и получает 409 (книга снята)"
  it=$(mk_item S6-RES2 7300)
  J='[{"external_item_id":"S6-RES2","status":"withdrawn","price_amount":7300,"currency":"EUR"}]'
  run_bg SYNC "SET @pause_at = 'sync.after_items'; CALL a_sync_start('primary', @r); CALL a_sync_batch(@r, 'primary', '$J'); CALL a_sync_finish(@r, 'primary');"
  run_bg U1 "CALL t_wait_paused('SYNC', 'sync.after_items'); CALL a_reserve(1603, $it);"
  waitall; timeline
  check S6.3a "reserve — 409 item_unavailable (withdrawn)" "$(oc U1 reserve) $(oj U1 reserve '$.availability_status')" "409 uniundata_item_unavailable withdrawn"

  sub S6.4 "два запуска синхронизации одновременно: GET_LOCK + UNIQUE(running_source)"
  run_bg SYNC1 "CALL a_sync_start('primary', @r); DO SLEEP(2); CALL a_sync_finish(@r, 'primary');"
  run SYNC2 "CALL t_wait_used('uniundata_sync_primary@$DB'); CALL a_sync_start('primary', @r2);"
  check S6.4a "второй запуск сразу выходит: GET_LOCK занят" "$(oc SYNC2 sync_start)" "200 locked"
  expect_err S6.4b "второй running-прогон источника на уровне данных (uq_sync_runs_one_running)" 1062 uq_sync_runs_one_running \
    "INSERT INTO wp_book_sync_runs (source_name, status, started_at, heartbeat_at) VALUES ('primary', 'running', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
  waitall
  setup "INSERT INTO wp_book_sync_runs (source_name, status, started_at, heartbeat_at) VALUES ('secondary', 'running', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));"
  run SYNC3 "CALL a_sync_start('secondary', @r3);"
  check S6.4c "процесс умер, строка running осталась: GET_LOCK свободен, но запуск видит running → busy" "$(oc SYNC3 sync_start)" "200 busy"
  q "UPDATE wp_book_sync_runs SET status = 'aborted', finished_at = UTC_TIMESTAMP(6) WHERE source_name = 'secondary' AND status = 'running'"
}

# ======================================================================================
# S7. Callback банка одновременно с задачей снятия просроченных заказов / резервов
# ======================================================================================
s7() {
  section S7 "Callback банка одновременно с задачей снятия истёкших резервов/заказов"
  local it o pp t0 k res

  sub S7.1 "webhook успел первым (держит экземпляр) → pass B ждёт и ничего не освобождает"
  it=$(mk_item S7-1 8000); o=$(mk_order 1701 "$it"); pp=$(pp_of "$o")
  run_bg WH "SET @pause_at = 'apply.before_commit'; CALL a_webhook('S7-1-evt', '$pp', 'succeeded', 8000, 'EUR', NULL);"
  run_bg CRON "CALL t_wait_paused('WH', 'apply.before_commit'); CALL a_expire_order_one($o, 'pending');" 41
  waitall; timeline
  check S7.1a "pass B ждал X-lock экземпляра, удерживаемый webhook" "$(traced WH '%CRON ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  check S7.1b "webhook processed; pass B — noop (заказ уже paid)" "$(oc WH webhook) / $(oc CRON expire_order) $(oj CRON expire_order '$.order_status')" "200 processed / 200 noop paid"
  check S7.1c "заказ paid, экземпляр sold, продажа одна" "$(ost "$o")/$(st "$it")/$(q "SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it")" "paid/sold/1"

  sub S7.2 "pass B успел первым (заказ payment_expired) → поздний платёж забирает свободный экземпляр"
  it=$(mk_item S7-2 8100); o=$(mk_order 1702 "$it"); pp=$(pp_of "$o")
  run_bg CRON "SET @pause_at = 'oexp.before_commit'; CALL a_expire_order_one($o, 'pending');" 41
  run_bg WH "CALL t_wait_paused('CRON', 'oexp.before_commit'); CALL a_webhook('S7-2-evt', '$pp', 'succeeded', 8100, 'EUR', NULL);"
  waitall; timeline
  check S7.2a "webhook ждал X-lock экземпляра, удерживаемый pass B" "$(traced CRON '%WH ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  check S7.2b "pass B: payment_expired; webhook: late_payment_reacquired" "$(oc CRON expire_order) / $(oj WH webhook '$.note')" "200 payment_expired / late_payment_reacquired"
  checkq S7.2c "заказ paid без флага, платёж expired→succeeded, экземпляр sold, продажа одна" \
    "SELECT CONCAT(o.status, ':', o.needs_attention, '/', p.status, '/', i.availability_status, '/', (SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it))
       FROM wp_book_orders o JOIN wp_book_payments p ON p.order_id = o.id JOIN wp_book_items i ON i.id = $it WHERE o.id = $o" "paid:0/succeeded/sold/1"
  checkq S7.2d "аудит: платёж подтверждён поздно (late_confirmation)" \
    "SELECT JSON_EXTRACT(context, '\$.late_confirmation') FROM wp_book_audit_log WHERE entity_type = 'payment' AND to_status = 'succeeded'
      AND entity_id = (SELECT id FROM wp_book_payments WHERE order_id = $o)" "true"

  sub S7.3 "pass B освободил, другой пользователь резервирует, и в этот момент приходит поздний платёж"
  it=$(mk_item S7-3 8200); o=$(mk_order 1703 "$it"); pp=$(pp_of "$o")
  run CRON "CALL a_expire_order_one($o, 'pending');" 41
  run_bg U2 "SET @pause_at = 'reserve.before_commit'; CALL a_reserve(1704, $it);"
  run_bg WH "CALL t_wait_paused('U2', 'reserve.before_commit'); CALL a_webhook('S7-3-evt', '$pp', 'succeeded', 8200, 'EUR', NULL);"
  waitall; timeline
  check S7.3a "webhook ждал X-lock экземпляра, который держит резерв U2" "$(traced U2 '%WH ждёт X,REC_NOT_GAP wp_book_items.PRIMARY%')" "1"
  check S7.3b "U2 получил резерв; webhook: late_payment_conflict" "$(oc U2 reserve) / $(oj WH webhook '$.note')" "201 created / late_payment_conflict"
  checkq S7.3c "заказ paid + needs_attention=late_payment_conflict, продажи нет, экземпляр за U2" \
    "SELECT CONCAT(o.status, ':', o.needs_attention, ':', o.attention_reason, '/', (SELECT COUNT(*) FROM wp_book_sales WHERE book_item_id = $it), '/',
                   i.availability_status, ':', (SELECT user_id FROM wp_book_reservations WHERE active_book_item_id = $it))
       FROM wp_book_orders o JOIN wp_book_items i ON i.id = $it WHERE o.id = $o" "paid:1:late_payment_conflict/0/reserved:1704"
  check S7.3d "в очередь поставлен возврат полной суммы" "$(oj WH webhook '$.refund_enqueued.amount') $(oj WH webhook '$.refund_enqueued.reason')" "8200 late_payment_conflict"

  sub S7.4 "pass A не освобождает экземпляр, по которому идёт оплата (checkout_pending)"
  it=$(mk_item S7-4 8300); o=$(mk_order 1705 "$it")
  run CRON "CALL a_expire_reservations(200);" 61
  check S7.4a "через 61 мин после резерва: заказ pending_payment, экземпляр checkout_pending" "$(ost "$o")/$(st "$it")" "pending_payment/checkout_pending"
  checkq S7.4b "резерв converted_to_order, не expired" \
    "SELECT reservation_status FROM wp_book_reservations WHERE book_item_id = $it" "converted_to_order"

  sub S7.5 "гонка на границе часа: checkout (59:xx) ↔ pass A (60:xx) — checkout первым"
  sweep
  it=$(mk_item S7-5 8400)
  setup "CALL a_reserve(1706, $it);"
  run_bg CO "SET @pause_at = 'checkout.before_commit'; CALL a_checkout(1706, '$(uuid)', 8400, 'EUR');" 59
  run_bg CRON "CALL t_wait_paused('CO', 'checkout.before_commit'); CALL a_expire_reservations(200);" 61
  waitall; timeline
  check S7.5a "pass A ждал корзину пользователя (1-й уровень порядка)" "$(traced CO '%CRON ждёт X,REC_NOT_GAP wp_book_carts.PRIMARY%')" "1"
  check S7.5b "checkout 201; pass A пропустил резерв (уже converted_to_order)" "$(oc CO checkout) / $(oc CRON expire_res) $(oj CRON expire_res '$.reservation_status')" "201 created / 200 skipped converted_to_order"
  check S7.5c "экземпляр checkout_pending" "$(st "$it")" "checkout_pending"

  sub S7.6 "гонка на границе часа: pass A первым → checkout получает 409, заказа нет"
  sweep
  it=$(mk_item S7-6 8500)
  setup "CALL a_reserve(1707, $it);"
  run_bg CRON "SET @pause_at = 'expA.before_commit'; CALL a_expire_reservations(200);" 61
  run_bg CO "CALL t_wait_paused('CRON', 'expA.before_commit'); CALL a_checkout(1707, '$(uuid)', 8500, 'EUR');" 59
  waitall; timeline
  check S7.6a "checkout ждал корзину, которую держит pass A" "$(traced CRON '%CO ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user%')" "1"
  check S7.6b "pass A: expired; checkout: 409 cart_empty (корзина закрыта как expired)" "$(oc CRON expire_res) / $(oc CO checkout)" "200 expired / 409 uniundata_cart_empty"
  check S7.6c "заказа нет, экземпляр available" "$(q "SELECT COUNT(*) FROM wp_book_orders WHERE user_id = 1707")/$(st "$it")" "0/available"

  sub S7.7 "контроль: webhook с НЕПРАВИЛЬНЫМ порядком (orders → items) против pass B (items → orders) = deadlock"
  it=$(mk_item S7-7 8600); o=$(mk_order 1708 "$it")
  run_bg BAD "SET @pause_at = 'pair.after_first'; CALL n_lock_pair('orders', $o, 'items', $it);"
  run_bg CRON "CALL t_wait_paused('BAD', 'pair.after_first'); CALL a_expire_order_one($o, 'pending');" 41
  waitall; timeline
  check S7.7a "MySQL обнаружил deadlock: ровно одна сессия получила ERROR 1213" "$(deadlocks)" "1"
  echo "           → тест умеет ловить deadlock; при глобальном порядке (S7.1, S7.2, S7.8) их 0"

  sub S7.8 "стресс: 12 заказов, webhook и pass B стартуют почти одновременно (±30 мс, без пауз)"
  local orders=() pps=() items=()
  for k in $(seq 1 12); do
    it=$(mk_item "S7-8-$k" $((9000 + k))); items+=("$it")
    o=$(mk_order $((1720 + k)) "$it"); orders+=("$o"); pps+=("$(pp_of "$o")")
  done
  t0=$(start_gun 1.0)
  for k in $(seq 0 11); do
    run_bg "W$k" "CALL t_start_at($t0 + $((k % 2 == 1 ? 3 : 0)) / 100); CALL t_retry('CALL a_webhook(''S7-8-evt-$k'', ''${pps[$k]}'', ''succeeded'', $((9001 + k)), ''EUR'', NULL)', 'webhook');"
    # чётные заказы: pass B стартует на 30 мс позже webhook, нечётные — webhook на 30 мс позже pass B
    run_bg "C$k" "CALL t_start_at($t0 + $((k % 2 == 0 ? 3 : 0)) / 100); CALL t_retry('CALL a_expire_order_one(${orders[$k]}, ''pending'')', 'expire_order');" 41
  done
  waitall
  check S7.8a "ни одного deadlock / lock wait timeout (24 сессии), повторов не понадобилось" "$(deadlocks)/$(retried)" "0/0"
  res=$(q "SELECT CONCAT(SUM(o.status = 'paid'), ' paid, ', SUM(i.availability_status = 'sold'), ' sold, ', COUNT(s.id), ' sales')
             FROM wp_book_orders o JOIN wp_book_order_items oi ON oi.order_id = o.id JOIN wp_book_items i ON i.id = oi.book_item_id
             LEFT JOIN wp_book_sales s ON s.book_item_id = i.id
            WHERE o.id IN ($(IFS=,; echo "${orders[*]}"))")
  check S7.8b "каждый заказ оплачен, каждый экземпляр продан ровно один раз" "$res" "12 paid, 12 sold, 12 sales"
  echo "           ветки: $(q "SELECT GROUP_CONCAT(CONCAT(n, '× ', k) SEPARATOR ', ') FROM (SELECT CONCAT(op, ':', IF(op = 'webhook', COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '\$.note')), ''), code), code)) k, COUNT(*) n FROM t_outcome WHERE scn = 'S7.8' GROUP BY k) x")"
}

# ======================================================================================
# S8. Корзина: одна открытая на пользователя, без дублей; reserve ↔ checkout без deadlock
# ======================================================================================
s8() {
  section S8 "Корзина и порядок блокировок"
  local a b c cart o t0 k res

  sub S8.1 "одна открытая корзина при параллельном создании (3 сессии, INSERT … ON DUPLICATE KEY UPDATE)"
  run_bg C1 "SET @rv_at = 'cart.after_select', @rv_peers = 'C1,C2,C3', @pause_at = 'cart.created', @pause_waiters = 2, @pause_timeout = 3; CALL a_get_cart(1801);"
  run_bg C2 "SET @rv_at = 'cart.after_select', @rv_peers = 'C1,C2,C3', @pause_at = 'cart.created', @pause_waiters = 2, @pause_timeout = 3; CALL a_get_cart(1801);"
  run_bg C3 "SET @rv_at = 'cart.after_select', @rv_peers = 'C1,C2,C3', @pause_at = 'cart.created', @pause_waiters = 2, @pause_timeout = 3; CALL a_get_cart(1801);"
  waitall; timeline
  checkq S8.1a "все три не нашли корзину и вставляли одновременно; ожидающих двое" \
    "SELECT COUNT(*) FROM t_trace WHERE scn = 'S8.1' AND msg LIKE 'ВИЖУ ОЖИДАНИЕ:%ждёт X%uq_carts_one_open_per_user%;%'" "1"
  checkq S8.1b "у пользователя одна корзина, все три сессии получили её id" \
    "SELECT CONCAT((SELECT COUNT(*) FROM wp_book_carts WHERE user_id = 1801), '/', COUNT(DISTINCT JSON_EXTRACT(data, '\$.cart_id')), '/', COUNT(*))
       FROM t_outcome WHERE scn = 'S8.1' AND op = 'cart' AND code = 'ok'" "1/1/3"
  check S8.1c "без deadlock" "$(deadlocks)" "0"

  sub S8.2 "контроль: «INSERT → 1062 → SELECT FOR UPDATE» (отвергнутый вариант) на 3 сессиях"
  run_bg N1 "SET @rv_at = 'ncart.after_select', @rv_peers = 'N1,N2,N3', @pause_at = 'ncart.created', @pause_waiters = 2, @pause_timeout = 3; CALL n_get_cart(1802);"
  run_bg N2 "SET @rv_at = 'ncart.after_select', @rv_peers = 'N1,N2,N3', @pause_at = 'ncart.created', @pause_waiters = 2, @pause_timeout = 3; CALL n_get_cart(1802);"
  run_bg N3 "SET @rv_at = 'ncart.after_select', @rv_peers = 'N1,N2,N3', @pause_at = 'ncart.created', @pause_waiters = 2, @pause_timeout = 3; CALL n_get_cart(1802);"
  waitall; timeline
  check S8.2a "после 1062 проигравшие держат S-lock и просят X → deadlock воспроизведён (поэтому в коде ODKU)" "$(deadlocks)" "1"
  checkq S8.2b "корзина всё равно одна (UNIQUE)" "SELECT COUNT(*) FROM wp_book_carts WHERE user_id = 1802" "1"

  sub S8.3 "одну книгу нельзя добавить дважды"
  a=$(mk_item S8-3 9100)
  run U1 "CALL a_reserve(1803, $a); CALL a_reserve(1803, $a);"
  check S8.3a "повторное «Отложить» своей книги — 200 existing" "$(oc U1 reserve)" "200 existing"
  checkq S8.3b "одна активная позиция" "SELECT COUNT(*) FROM wp_book_cart_items WHERE book_item_id = $a AND status = 'active'" "1"
  cart=$(q "SELECT id FROM wp_book_carts WHERE open_cart_user_id = 1803")
  q "INSERT INTO wp_book_reservations (book_item_id, user_id, reservation_status, attempt_no, reserved_at, expires_at, released_at)
     VALUES ($a, 1899, 'cancelled', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 1 HOUR, UTC_TIMESTAMP(6))"
  expect_err S8.3c "вторая активная позиция той же книги даже в той же корзине (uq_cart_items_one_active_per_item)" 1062 uq_cart_items_one_active_per_item \
    "INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at)
     SELECT cart_id, book_item_id, (SELECT MAX(id) FROM wp_book_reservations WHERE user_id = 1899), 1, 'EUR', 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 1 HOUR
       FROM wp_book_cart_items WHERE cart_id = $cart AND status = 'active'"

  sub S8.4 "reserve и checkout одного пользователя параллельно — checkout первым"
  a=$(mk_item S8-4A 9200); b=$(mk_item S8-4B 9300); c=$(mk_item S8-4C 9400)
  setup "CALL a_reserve(1804, $a); CALL a_reserve(1804, $b);"
  run_bg CO "SET @pause_at = 'checkout.after_items'; CALL a_checkout(1804, '$(uuid)', 18500, 'EUR');"
  run_bg RS "CALL t_wait_paused('CO', 'checkout.after_items'); CALL a_reserve(1804, $c);"
  waitall; timeline
  check S8.4a "reserve ждал на корзине (уровень 1), а не на экземплярах" "$(traced CO '%RS ждёт X,REC_NOT_GAP wp_book_carts.%')" "1"
  check S8.4b "checkout 201 (A+B); reserve 201 в НОВОЙ корзине" \
    "$(oc CO checkout) / $(oc RS reserve) / $(q "SELECT IF(c.status = 'active' AND c.id <> o.cart_id, 'new_cart', 'same_cart') FROM wp_book_carts c, wp_book_orders o WHERE c.open_cart_user_id = 1804 AND o.user_id = 1804")" \
    "201 created / 201 created / new_cart"
  check S8.4c "без deadlock" "$(deadlocks)" "0"

  sub S8.5 "reserve и checkout одного пользователя параллельно — reserve первым"
  a=$(mk_item S8-5A 9500); b=$(mk_item S8-5B 9600); c=$(mk_item S8-5C 9700)
  setup "CALL a_reserve(1805, $a); CALL a_reserve(1805, $b);"
  run_bg RS "SET @pause_at = 'reserve.before_commit'; CALL a_reserve(1805, $c);"
  run_bg CO "CALL t_wait_paused('RS', 'reserve.before_commit'); CALL a_checkout(1805, '$(uuid)', 19100, 'EUR');"
  waitall; timeline
  check S8.5a "checkout ждал корзину; увидел три книги → 409 cart_changed (сумма 28800 ≠ 19100)" \
    "$(oc RS reserve) / $(oc CO checkout) $(oj CO checkout '$.actual_total_amount')" "201 created / 409 uniundata_cart_changed 28800"
  check S8.5b "без deadlock" "$(deadlocks)" "0"

  sub S8.6 "контроль: remove-item с НЕПРАВИЛЬНЫМ порядком (items → carts) против checkout (carts → items) = deadlock"
  a=$(mk_item S8-6A 9800); b=$(mk_item S8-6B 9900)
  setup "CALL a_reserve(1806, $a); CALL a_reserve(1806, $b);"
  cart=$(q "SELECT id FROM wp_book_carts WHERE open_cart_user_id = 1806")
  run_bg BAD "SET @pause_at = 'pair.after_first'; CALL n_lock_pair('items', $a, 'carts', $cart);"
  run_bg CO "CALL t_wait_paused('BAD', 'pair.after_first'); CALL a_checkout(1806, '$(uuid)', 19700, 'EUR');"
  waitall; timeline
  check S8.6a "MySQL обнаружил deadlock (ровно один 1213)" "$(deadlocks)" "1"

  sub S8.7 "стресс: 3 раунда × 12 пользователей, у каждого checkout(A+B) и reserve(C) стартуют одновременно (повтор как Db::transaction)"
  local users=() round u
  for round in 1 2 3; do
    users=()
    for k in $(seq 1 12); do
      u=$((1800 + round * 100 + k))
      a=$(mk_item "S8-7-$round-${k}A" 1000); b=$(mk_item "S8-7-$round-${k}B" 1000); c=$(mk_item "S8-7-$round-${k}C" 1000)
      setup "CALL a_reserve($u, $a); CALL a_reserve($u, $b);"
      users+=("$u:$c")
    done
    t0=$(start_gun 0.8)
    for k in $(seq 1 12); do
      u=${users[$((k - 1))]%%:*}
      run_bg "CO$round.$k" "CALL t_start_at($t0); CALL t_retry('CALL a_checkout($u, ''$(uuid)'', 2000, ''EUR'')', 'checkout');"
      run_bg "RS$round.$k" "CALL t_start_at($t0); CALL t_retry('CALL a_reserve($u, ${users[$((k - 1))]#*:})', 'reserve');"
    done
    waitall
  done
  check S8.7a "итог: ни одной операции, упавшей по deadlock / lock wait timeout (с повтором)" "$(deadlocks)" "0"
  checkq S8.7b "все 36 reserve(C) успешны" "SELECT COUNT(*) FROM t_outcome WHERE scn = 'S8.7' AND op = 'reserve' AND code = 'created' AND retried = 0" "36"
  checkq S8.7c "каждый checkout: заказ A+B (201) или 409 cart_changed (C успел первым) — других исходов нет" \
    "SELECT COUNT(*) FROM t_outcome WHERE scn = 'S8.7' AND op = 'checkout' AND retried = 0 AND code IN ('created', 'uniundata_cart_changed')" "36"
  checkq S8.7d "порядок блокировок не участвует: каждый deadlock (если был) — на INSERT новой корзины (gap-lock проверки UNIQUE)" \
    "SELECT COUNT(*) FROM t_trace r WHERE r.scn = 'S8.7' AND r.msg LIKE 'ROLLBACK → % (errno 1213%'
        AND COALESCE((SELECT p.msg FROM t_trace p WHERE p.scn = r.scn AND p.sess = r.sess AND p.id < r.id ORDER BY p.id DESC LIMIT 1), '')
            NOT LIKE 'открытой корзины нет%'" "0"
  xflaky S8.7e "без повтора: ни одного deadlock" "$(retried) повторено" "0 повторено" \
    "INSERT … ON DUPLICATE KEY UPDATE новой корзины проверяет дубликат по delete-marked записи только что закрытой корзины в uq_carts_one_open_per_user (generated column) и ставит next-key/gap-блокировки даже в READ COMMITTED; две такие вставки соседних user_id ждут insert intention друг друга. Инварианты не нарушаются, Db::transaction() повторяет транзакцию"
  if [[ "$(retried)" != 0 ]]; then echo "           последний deadlock InnoDB (SHOW ENGINE INNODB STATUS):"; last_deadlock; fi
  echo "           исходы checkout: $(q "SELECT GROUP_CONCAT(CONCAT(n, '× ', code) SEPARATOR ', ') FROM (SELECT code, COUNT(*) n FROM t_outcome WHERE scn = 'S8.7' AND op = 'checkout' AND retried = 0 GROUP BY code) x")"

  sub S8.8 "двойной клик «Перейти к оплате» с тем же Idempotency-Key"
  a=$(mk_item S8-8 2222)
  setup "CALL a_reserve(1807, $a);"
  k=$(uuid)
  run_bg CO1 "SET @pause_at = 'checkout.before_commit'; CALL a_checkout(1807, '$k', 2222, 'EUR');"
  run_bg CO2 "CALL t_wait_paused('CO1', 'checkout.before_commit'); CALL a_checkout(1807, '$k', 2222, 'EUR');"
  waitall; timeline
  check S8.8a "первый 201, второй 200 replayed с тем же заказом" \
    "$(oc CO1 checkout) / $(oc CO2 checkout) #$(oj CO2 checkout '$.order_id')" "201 created / 200 replayed #$(oj CO1 checkout '$.order_id')"
  checkq S8.8b "в БД один заказ" "SELECT COUNT(*) FROM wp_book_orders WHERE user_id = 1807" "1"
}

# ======================================================================================
# S9. Глобальные инварианты после всех сценариев
# ======================================================================================
s9() {
  section S9 "Глобальные инварианты данных после всех сценариев (tests/mysql/invariants.sql)"
  SCN=S9
  local name n
  while IFS=$'\t' read -r name n; do
    check "${name%% *}" "${name#* }" "$n" "0"
  done < <(q "SELECT name, violations FROM t_invariants")
  checkq S9.dl "неповторённых deadlock (1213) вне контрольных сценариев S7.7, S8.2, S8.6 — ни одного" \
    "SELECT COUNT(*) FROM t_outcome WHERE errno = 1213 AND retried = 0 AND scn NOT IN ('S7.7', 'S8.2', 'S8.6')" "0"
  echo "           повторённых (как Db::transaction) deadlock-ов за прогон: $(q "SELECT COALESCE(GROUP_CONCAT(CONCAT(scn, ': ', n) SEPARATOR ', '), '0') FROM (SELECT scn, COUNT(*) n FROM t_outcome WHERE retried = 1 GROUP BY scn) x")"
  checkq S9.lw "lock wait timeout (1205) — ни одного" "SELECT COUNT(*) FROM t_outcome WHERE errno = 1205" "0"
  echo "--- индексы точечных запросов алгоритмов (EXPLAIN на реальных строках после сценариев)"
  local v
  v=$(q "SELECT MAX(open_cart_user_id) FROM wp_book_carts")
  [[ "$v" != NULL ]] && check S9.ix1 "открытая корзина: WHERE open_cart_user_id = ? → uq_carts_one_open_per_user" \
    "$(q "EXPLAIN SELECT id FROM wp_book_carts WHERE open_cart_user_id = $v FOR UPDATE" | cut -f7)" "uq_carts_one_open_per_user"
  v=$(q "SELECT MAX(active_book_item_id) FROM wp_book_reservations")
  [[ "$v" != NULL ]] && check S9.ix2 "active-резерв: WHERE active_book_item_id = ? → uq_reservations_one_active_per_item" \
    "$(q "EXPLAIN SELECT id FROM wp_book_reservations WHERE active_book_item_id = $v FOR UPDATE" | cut -f7)" "uq_reservations_one_active_per_item"
  v=$(q "SELECT MAX(provider_payment_id) FROM wp_book_payments")
  [[ "$v" != NULL ]] && check S9.ix3 "webhook: платёж по (provider, provider_payment_id) → uq_payments_provider_id" \
    "$(q "EXPLAIN SELECT id FROM wp_book_payments WHERE provider = 'testbank' AND provider_payment_id = '$v'" | cut -f7)" "uq_payments_provider_id"
  v=$(q "SELECT MAX(book_item_id) FROM wp_book_sales")
  [[ "$v" != NULL ]] && check S9.ix4 "продажа экземпляра: WHERE book_item_id = ? → uq_sales_book_item" \
    "$(q "EXPLAIN SELECT id FROM wp_book_sales WHERE book_item_id = $v" | cut -f7)" "uq_sales_book_item"
  v=$(q "SELECT MAX(order_id) FROM wp_book_payments")
  [[ "$v" != NULL ]] && check S9.ix5 "платежи заказа FOR UPDATE: WHERE order_id = ? → uq_payments_attempt" \
    "$(q "EXPLAIN SELECT id FROM wp_book_payments WHERE order_id = $v FOR UPDATE" | cut -f7)" "uq_payments_attempt"
  return 0
}

for s in S0 S1 S2 S3 S4 S5 S6 S7 S8; do
  want "$s" && "${s,,}"
done
s9

echo
echo "======================================================================================"
printf 'ИТОГ: PASS %d, FAIL %d, EXPECTED-FAIL %d, XPASS %d\n' "$PASS" "$FAIL" "$XFAIL" "$XPASS"
if ((FAIL > 0)); then
  echo "Провалены: ${FAILED[*]}"
  exit 1
fi
exit 0
