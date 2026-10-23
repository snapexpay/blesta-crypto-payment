<?php
// Basics
$lang['SnapexPay.name'] = 'SnapEX Pay (криптовалюта)';
$lang['SnapexPay.description'] = 'Приём USDT в нескольких сетях и других монет с обменом в USDT. Деньги приходят на баланс мерчанта SnapEX Pay.';

// Settings
$lang['SnapexPay.api_key'] = 'API-ключ';
$lang['SnapexPay.api_key_note'] = 'Кабинет мерчанта SnapEX Pay → API-ключи. Права: invoices.read и invoices.write.';
$lang['SnapexPay.webhook_secret'] = 'Секрет вебхука';
$lang['SnapexPay.webhook_secret_note'] = 'В кабинете SnapEX Pay укажите адрес вебхука ниже и вставьте сюда секрет whsec_….';
$lang['SnapexPay.webhook_url'] = 'Адрес вебхука для кабинета SnapEX Pay';
$lang['SnapexPay.fee_on'] = 'Комиссию сети платит';
$lang['SnapexPay.fee_on.merchant'] = 'Продавец (клиент платит сумму счёта)';
$lang['SnapexPay.fee_on.customer'] = 'Клиент (добавляется к сумме счёта)';
$lang['SnapexPay.expires_minutes'] = 'Время на оплату, минут';
$lang['SnapexPay.expires_minutes_note'] = 'Сколько действует страница оплаты после открытия (1–1440).';
$lang['SnapexPay.test_mode'] = 'Тестовый режим';
$lang['SnapexPay.test_mode_note'] = 'Тестовые счета подтверждаются сами, без настоящих денег. Пока режим включён, кнопку видит только персонал (вход под клиентом).';
$lang['SnapexPay.partner_id'] = 'Partner ID';
$lang['SnapexPay.partner_id_note'] = 'Необязательно. Partner ID интегратора, который подключил модуль (партнёрская программа SnapEX Pay). Если подключали сами — оставьте пустым.';
$lang['SnapexPay.api_url'] = 'Адрес API';
$lang['SnapexPay.api_url_note'] = 'Не меняйте.';

// Payment page
$lang['SnapexPay.buildprocess.submit'] = 'Оплатить криптовалютой';
$lang['SnapexPay.process.test_mode'] = 'Тестовый режим: настоящие деньги не списываются.';
$lang['SnapexPay.process.test_mode_clients'] = 'Оплата криптовалютой настраивается. Пока выберите другой способ оплаты.';

// Transactions
$lang['SnapexPay.transaction.partial'] = 'SnapEX Pay: оплачено частично (получено %1$s). Проверьте и зачтите вручную.';

// Errors
$lang['SnapexPay.!error.api_key.empty'] = 'Укажите API-ключ SnapEX Pay.';
$lang['SnapexPay.!error.webhook_secret.empty'] = 'Укажите секрет вебхука: Blesta отмечает счета оплаченными по вебхуку SnapEX Pay.';
$lang['SnapexPay.!error.fee_on.valid'] = 'Выберите, кто платит комиссию сети.';
$lang['SnapexPay.!error.expires_minutes.valid'] = 'Время на оплату — целое число минут от 1 до 1440.';
$lang['SnapexPay.!error.partner_id.valid'] = 'Partner ID — не длиннее 32 символов.';
$lang['SnapexPay.!error.api_url.valid'] = 'Адрес API должен начинаться с https://.';
$lang['SnapexPay.!error.currency.usd_missing'] = 'SnapEX Pay работает в USD: добавьте USD в Settings → Company → Currencies, чтобы суммы в %1$s пересчитывались.';
$lang['SnapexPay.!error.amount.invalid'] = 'Нечего оплачивать.';
$lang['SnapexPay.!error.create'] = 'Не удалось начать оплату криптовалютой: %1$s';
$lang['SnapexPay.!error.unreachable'] = 'Не удалось связаться с SnapEX Pay, чтобы подтвердить оплату: %1$s';
$lang['SnapexPay.!error.mismatch'] = 'Счёт SnapEX Pay не совпадает с платежом, для которого создан. Ничего не записано.';
$lang['SnapexPay.!error.webhook.stale'] = 'У вебхука нет метки времени или она устарела.';
$lang['SnapexPay.!error.webhook.signature'] = 'Подпись вебхука не совпадает с секретом.';
$lang['SnapexPay.!error.check.token'] = 'Неверная ссылка проверки оплаты.';
